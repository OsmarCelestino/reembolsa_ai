<?php

use App\Ai\Contracts\ReceiptExtractor;
use App\Ai\Exceptions\AiProviderUnavailable;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeReceiptExtractor;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

/*
| Integração com o RabbitMQ de verdade (serviço "rabbitmq" do compose): o pedido é
| publicado na fila e um worker o consome. A IA continua simulada.
|
| Cada teste usa uma fila própria e descartável, para o worker do serviço "queue"
| (que consome a fila "default") não pegar as mensagens. Sem RabbitMQ acessível,
| os testes são pulados: rode com `./vendor/bin/pest --group=rabbitmq` dentro do container.
*/

pest()->group('rabbitmq');

beforeEach(function () {
    Storage::fake('local');

    $this->queueName = 'pest-'.bin2hex(random_bytes(4));
    config([
        'queue.default' => 'rabbitmq',
        'queue.connections.rabbitmq.queue' => $this->queueName,
    ]);

    try {
        $this->rabbit = Queue::connection('rabbitmq');
        $this->rabbit->size();
    } catch (Throwable $e) {
        $this->markTestSkipped('RabbitMQ indisponível: '.$e->getMessage());
    }

    Sanctum::actingAs(User::factory()->create());
});

afterEach(function () {
    if (isset($this->rabbit) && $this->rabbit instanceof RabbitMQQueue) {
        foreach ([$this->queueName, "{$this->queueName}.delay.30000"] as $name) {
            if ($this->rabbit->isQueueExists($name)) {
                $this->rabbit->deleteQueue($name);
            }
        }
    }
});

function submitViaApi(): Reimbursement
{
    $id = test()->postJson('/api/reimbursements', [
        'category' => 'alimentacao',
        'claimed_amount' => '45,90',
        'receipt' => pdfUpload(bin2hex(random_bytes(4))),
    ])->assertAccepted()->json('data.id');

    return Reimbursement::findOrFail($id);
}

/** Roda um worker no mesmo processo (enxerga o banco em memória do teste) e processa uma mensagem. */
function workOnce(): void
{
    test()->artisan('queue:work', ['connection' => 'rabbitmq', '--queue' => test()->queueName, '--once' => true, '--sleep' => 0])
        ->assertSuccessful();
}

it('publica o pedido no RabbitMQ e o worker processa', function () {
    app()->instance(ReceiptExtractor::class, new FakeReceiptExtractor(receipt()));

    $r = submitViaApi();

    expect($r->status)->toBe(ReimbursementStatus::Submitted)
        ->and($this->rabbit->size($this->queueName))->toBe(1);

    workOnce();

    expect($r->refresh()->status)->toBe(ReimbursementStatus::AutoApproved)
        ->and($this->rabbit->size($this->queueName))->toBe(0);
});

it('com a IA fora do ar, devolve a mensagem para a fila de espera sem perder o pedido', function () {
    app()->instance(ReceiptExtractor::class, new FakeReceiptExtractor(throw: new AiProviderUnavailable('529')));

    $r = submitViaApi();

    workOnce();

    // 1ª nova tentativa depois de 30 s (ProcessReimbursementJob::$backoff).
    expect($r->refresh()->status)->toBe(ReimbursementStatus::Submitted)
        ->and($this->rabbit->size($this->queueName))->toBe(0)
        ->and($this->rabbit->size("{$this->queueName}.delay.30000"))->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});
