<?php

use App\Enums\ReimbursementStatus;
use App\Jobs\ProcessReimbursementJob;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create();
});

function payload(array $overrides = []): array
{
    return array_merge([
        'category' => 'alimentacao',
        'claimed_amount' => '45,90',
        'description' => 'Almoço com cliente',
        'receipt' => pdfUpload(),
    ], $overrides);
}

it('exige autenticação', function () {
    $this->postJson('/api/reimbursements', payload())->assertUnauthorized();
});

it('aceita o pedido, guarda o arquivo em disco privado e enfileira o processamento', function () {
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/reimbursements', payload())
        ->assertAccepted()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.claimed_amount_cents', 4590)
        ->assertJsonMissingPath('data.receipt_path');

    $r = Reimbursement::findOrFail($response->json('data.id'));

    expect($r->user_id)->toBe($this->user->id)
        ->and($r->receipt_mime)->toBe('application/pdf')
        ->and($r->receipt_path)->toStartWith('receipts/')->not->toContain('nota.pdf');

    Storage::disk('local')->assertExists($r->receipt_path);
    Storage::disk('public')->assertMissing($r->receipt_path);
    Queue::assertPushed(ProcessReimbursementJob::class, fn ($job) => $job->reimbursementId === $r->id);
});

it('valida os campos', function (array $override, string $field) {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/reimbursements', payload($override))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'categoria inexistente' => [['category' => 'luxo'], 'category'],
    'valor negativo' => [['claimed_amount' => '-10'], 'claimed_amount'],
    'valor zero' => [['claimed_amount' => '0'], 'claimed_amount'],
    'valor com SQL' => [['claimed_amount' => '10; DROP TABLE reimbursements'], 'claimed_amount'],
    'descrição longa' => [['description' => str_repeat('a', 501)], 'description'],
    'sem arquivo' => [['receipt' => null], 'receipt'],
]);

it('recusa arquivo que não é imagem/PDF de verdade, mesmo com extensão permitida', function (string $contents, string $name) {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/reimbursements', payload(['receipt' => realUpload($contents, $name)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('receipt');

    expect(Reimbursement::count())->toBe(0);
})->with([
    'PHP disfarçado de jpg' => ['<?php system($_GET["c"]); ?>', 'nota.jpg'],
    'HTML disfarçado de pdf' => ['<html><script>alert(1)</script></html>', 'nota.pdf'],
    'PDF com extensão .php' => ["%PDF-1.4\n%%EOF\n", 'nota.php'],
]);

it('recusa o reenvio do mesmo arquivo', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/reimbursements', payload(['receipt' => pdfUpload('igual')]))->assertAccepted();
    $this->postJson('/api/reimbursements', payload(['receipt' => pdfUpload('igual')]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('receipt');
});

it('ignora campos internos enviados pelo cliente (mass assignment)', function () {
    Sanctum::actingAs($this->user);
    $other = User::factory()->create();

    $id = $this->postJson('/api/reimbursements', payload([
        'status' => 'approved',
        'user_id' => $other->id,
        'ai_confidence' => 1,
        'reviewer_id' => $other->id,
    ]))->assertAccepted()->json('data.id');

    $r = Reimbursement::findOrFail($id);
    expect($r->status)->toBe(ReimbursementStatus::Submitted)
        ->and($r->user_id)->toBe($this->user->id)
        ->and($r->ai_confidence)->toBeNull()
        ->and($r->reviewer_id)->toBeNull();
});

it('guarda texto malicioso como texto, sem executar nada no banco', function () {
    Sanctum::actingAs($this->user);
    $evil = "Almoço'); DROP TABLE reimbursements; --";

    $this->postJson('/api/reimbursements', payload(['description' => $evil]))->assertAccepted();

    expect(Schema::hasTable('reimbursements'))->toBeTrue()
        ->and(Reimbursement::first()->description)->toBe($evil);
});

it('limita a quantidade de envios por minuto', function () {
    config(['reimbursement.submit_rate_per_minute' => 2]);
    Sanctum::actingAs($this->user);

    $this->postJson('/api/reimbursements', payload(['receipt' => pdfUpload('a')]))->assertAccepted();
    $this->postJson('/api/reimbursements', payload(['receipt' => pdfUpload('b')]))->assertAccepted();
    $this->postJson('/api/reimbursements', payload(['receipt' => pdfUpload('c')]))->assertTooManyRequests();
});
