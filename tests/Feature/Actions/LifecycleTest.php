<?php

use App\Actions\Reimbursements\ProcessReimbursement;
use App\Ai\Contracts\ReceiptExtractor;
use App\Ai\Exceptions\AiProviderUnavailable;
use App\Enums\ReimbursementStatus;
use App\Jobs\ProcessReimbursementJob;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeReceiptExtractor;

beforeEach(function () {
    Storage::fake('local');
});

function storedReimbursement(array $attrs = []): Reimbursement
{
    $r = Reimbursement::factory()->create($attrs);
    Storage::disk('local')->put($r->receipt_path, '%PDF-1.4 fake');

    return $r;
}

it('registra cada passo do ciclo de vida na auditoria', function () {
    app()->instance(ReceiptExtractor::class, new FakeReceiptExtractor(receipt()));
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $id = $this->postJson('/api/reimbursements', [
        'category' => 'alimentacao', 'claimed_amount' => '45.90', 'receipt' => pdfUpload('ciclo'),
    ])->assertAccepted()->json('data.id');

    app(ProcessReimbursement::class)->handle($id);

    $steps = Reimbursement::findOrFail($id)->auditLogs->map(fn ($l) => [$l->from_status?->value, $l->to_status->value, $l->actor_id, $l->reason])->all();

    expect($steps)->toBe([
        [null, 'submitted', $user->id, null],
        ['submitted', 'processing', null, null],
        ['processing', 'auto_approved', null, 'ai_decision'],
    ]);
});

it('o banco impede dois pedidos ativos com o mesmo comprovante', function () {
    Reimbursement::factory()->create(['receipt_fingerprint' => str_repeat('a', 64), 'status' => ReimbursementStatus::AutoApproved]);

    // Dentro de uma transação (savepoint), como no código de produção: no Postgres o erro aborta a transação.
    expect(fn () => DB::transaction(fn () => Reimbursement::factory()->create(['receipt_fingerprint' => str_repeat('a', 64), 'status' => ReimbursementStatus::PendingReview])))
        ->toThrow(UniqueConstraintViolationException::class);

    // Recusado não bloqueia.
    Reimbursement::factory()->create(['receipt_fingerprint' => str_repeat('a', 64), 'status' => ReimbursementStatus::Rejected]);
    expect(Reimbursement::count())->toBe(2);
});

it('corrida: outro pedido com o mesmo comprovante gravado durante o processamento vira recusa por duplicidade', function () {
    app()->instance(ReceiptExtractor::class, new FakeReceiptExtractor(receipt()));
    $target = storedReimbursement();
    $fingerprint = receipt()->fingerprint();

    // Simula o concorrente: logo antes de gravar a decisão, outro processo grava o mesmo comprovante.
    $raced = false;
    Reimbursement::saving(function (Reimbursement $r) use ($target, $fingerprint, &$raced) {
        if (! $raced && $r->id === $target->id && $r->receipt_fingerprint === $fingerprint) {
            $raced = true;
            Reimbursement::factory()->create(['receipt_fingerprint' => $fingerprint, 'status' => ReimbursementStatus::AutoApproved]);
        }
    });

    app(ProcessReimbursement::class)->handle($target->id);

    $target->refresh();
    expect($raced)->toBeTrue()
        ->and($target->status)->toBe(ReimbursementStatus::Rejected)
        ->and(collect($target->violations)->pluck('code'))->toContain('duplicate_receipt');
});

it('corrida no envio: o mesmo arquivo enviado ao mesmo tempo vira 422 e o arquivo extra é apagado', function () {
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());
    $sha = hash('sha256', file_get_contents(($upload = pdfUpload('simultaneo'))->getRealPath()));

    $raced = false;
    Reimbursement::creating(function (Reimbursement $r) use ($sha, &$raced) {
        if (! $raced && $r->receipt_sha256 === $sha) {
            $raced = true;
            Reimbursement::factory()->create(['receipt_sha256' => $sha]);
        }
    });

    $this->postJson('/api/reimbursements', ['category' => 'alimentacao', 'claimed_amount' => '10', 'receipt' => $upload])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('receipt');

    expect(Storage::disk('local')->allFiles('receipts'))->toBe([]);
});

it('analista reprocessa um pedido que falhou', function () {
    Queue::fake();
    $r = storedReimbursement(['status' => ReimbursementStatus::Failed]);
    $reviewer = User::factory()->reviewer()->create();
    Sanctum::actingAs($reviewer);

    $this->postJson("/api/reimbursements/{$r->id}/reprocess")->assertAccepted()->assertJsonPath('data.status', 'submitted');

    Queue::assertPushed(ProcessReimbursementJob::class, fn ($job) => $job->reimbursementId === $r->id);
    expect($r->auditLogs()->latest('id')->first())
        ->reason->toBe('reprocess')
        ->actor_id->toBe($reviewer->id);
});

it('não reprocessa pedido que não falhou, e funcionário não reprocessa', function () {
    $r = storedReimbursement(['status' => ReimbursementStatus::AutoApproved]);

    Sanctum::actingAs(User::factory()->reviewer()->create());
    $this->postJson("/api/reimbursements/{$r->id}/reprocess")->assertStatus(409);

    Sanctum::actingAs(User::factory()->create());
    $this->postJson("/api/reimbursements/{$r->id}/reprocess")->assertForbidden();
});

it('o comando reprocessa todos os pedidos com falha', function () {
    Queue::fake();
    storedReimbursement(['status' => ReimbursementStatus::Failed]);
    storedReimbursement(['status' => ReimbursementStatus::Failed]);
    storedReimbursement(['status' => ReimbursementStatus::AutoApproved]);

    $this->artisan('reembolso:reprocess-failed')->expectsOutputToContain('2 pedido(s)')->assertSuccessful();

    Queue::assertPushed(ProcessReimbursementJob::class, 2);
});

it('job esgotado registra o motivo na auditoria', function () {
    $r = storedReimbursement();

    (new ProcessReimbursementJob($r->id))->failed(new AiProviderUnavailable('down'));

    expect($r->refresh()->status)->toBe(ReimbursementStatus::Failed)
        ->and($r->auditLogs->last()->reason)->toBe('retries_exhausted');
});
