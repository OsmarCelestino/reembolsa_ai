<?php

use App\Enums\AuditAction;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->create();
    $this->reviewer = User::factory()->reviewer()->create();
    $this->r = Reimbursement::factory()->for($this->owner)->pendingReview()->create(['receipt_path' => 'receipts/abc.pdf']);
    Storage::disk('local')->put('receipts/abc.pdf', '%PDF-1.4 conteúdo');
});

it('analista baixa o comprovante com cabeçalhos seguros e o acesso fica auditado', function () {
    Sanctum::actingAs($this->reviewer);

    $response = $this->get("/api/reimbursements/{$this->r->id}/receipt")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Disposition'))->toContain('comprovante-'.$this->r->id.'.pdf')
        ->and($response->headers->get('Content-Disposition'))->not->toContain('abc');

    $log = $this->r->auditLogs()->where('action', AuditAction::ReceiptDownloaded)->sole();
    expect($log->actor_id)->toBe($this->reviewer->id);
});

it('dono também baixa; outro funcionário recebe 404', function () {
    Sanctum::actingAs($this->owner);
    $this->get("/api/reimbursements/{$this->r->id}/receipt")->assertOk();

    Sanctum::actingAs(User::factory()->create());
    $this->get("/api/reimbursements/{$this->r->id}/receipt")->assertNotFound();
});

it('comprovante descartado pela retenção responde 410', function () {
    $this->r->forceFill(['receipt_purged_at' => now()])->save();
    Sanctum::actingAs($this->reviewer);

    $this->getJson("/api/reimbursements/{$this->r->id}/receipt")->assertStatus(410);
});

it('o detalhe mostra a linha do tempo com quem fez cada mudança', function () {
    Sanctum::actingAs($this->reviewer);
    $this->postJson("/api/reimbursements/{$this->r->id}/review", ['decision' => 'approve'])->assertOk();

    $this->getJson("/api/reimbursements/{$this->r->id}")
        ->assertOk()
        ->assertJsonPath('data.history.0.action', 'status_changed')
        ->assertJsonPath('data.history.0.from', 'pending_review')
        ->assertJsonPath('data.history.0.to', 'approved')
        ->assertJsonPath('data.history.0.actor_id', $this->reviewer->id)
        ->assertJsonPath('data.history.0.reason', 'manual_review');
});

it('excluir o pedido apaga o arquivo do disco (LGPD)', function () {
    $this->r->delete();

    Storage::disk('local')->assertMissing('receipts/abc.pdf');
});
