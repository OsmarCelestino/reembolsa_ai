<?php

use App\Enums\AuditAction;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    config(['reimbursement.retention_days' => 30]);
});

function oldReimbursement(ReimbursementStatus $status, int $daysAgo): Reimbursement
{
    $r = Reimbursement::factory()->create(['status' => $status, 'description' => 'Almoço com Fulano', 'created_at' => now()->subDays($daysAgo)]);
    Storage::disk('local')->put($r->receipt_path, '%PDF');

    return $r;
}

it('descarta só comprovantes de pedidos encerrados e vencidos', function () {
    $expired = oldReimbursement(ReimbursementStatus::Approved, 40);
    $recent = oldReimbursement(ReimbursementStatus::Approved, 10);
    $open = oldReimbursement(ReimbursementStatus::PendingReview, 40);

    $this->artisan('reembolso:purge-receipts')->expectsOutputToContain('1 comprovante(s) descartado(s)')->assertSuccessful();

    Storage::disk('local')->assertMissing($expired->receipt_path);
    Storage::disk('local')->assertExists([$recent->receipt_path, $open->receipt_path]);

    $expired->refresh();
    expect($expired->receipt_purged_at)->not->toBeNull()
        ->and($expired->description)->toBeNull()
        ->and($expired->auditLogs->last()->action)->toBe(AuditAction::ReceiptPurged);
});

it('dry-run não apaga nada', function () {
    $r = oldReimbursement(ReimbursementStatus::Rejected, 40);

    $this->artisan('reembolso:purge-receipts --dry-run')->expectsOutputToContain('1 comprovante(s)')->assertSuccessful();

    Storage::disk('local')->assertExists($r->receipt_path);
});

it('está agendado para rodar todo dia', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('reembolso:purge-receipts')->assertSuccessful();
});

it('anonimiza o usuário, revoga tokens e mantém os pedidos', function () {
    $user = User::factory()->create(['name' => 'Maria Silva', 'email' => 'maria@empresa.com']);
    $user->createToken('celular');
    $r = Reimbursement::factory()->for($user)->create(['description' => 'Táxi para a casa da Maria']);

    $this->artisan("reembolso:anonymize-user {$user->id} --force")->assertSuccessful();

    $user->refresh();
    expect($user->name)->toBe('Usuário anonimizado')
        ->and($user->email)->not->toContain('maria')
        ->and($user->tokens()->count())->toBe(0)
        ->and($r->refresh()->description)->toBeNull()
        ->and(Reimbursement::count())->toBe(1);
});

it('não manda a descrição do usuário para a IA e pede para não transcrever CPF', function () {
    Http::fake(['*' => Http::response([
        'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'id' => 't', 'name' => 'register_receipt', 'input' => [
            'cnpj' => null, 'issuer_name' => null, 'issue_date' => null, 'total_amount' => null,
            'category' => null, 'confidence' => 0.1, 'suspicious_content' => false,
        ]]],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ])]);
    config(['llm.anthropic.api_key' => 'k', 'queue.default' => 'sync']);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/reimbursements', [
        'category' => 'alimentacao', 'claimed_amount' => '10', 'description' => 'CPF 123.456.789-00 segredo',
        'receipt' => pdfUpload('lgpd'),
    ])->assertAccepted();

    Http::assertSent(fn ($request) => ! str_contains($request->body(), '123.456.789-00')
        && str_contains($request->data()['system'], 'CPF'));
});
