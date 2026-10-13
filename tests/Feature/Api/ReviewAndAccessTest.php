<?php

use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->employee = User::factory()->create();
    $this->reviewer = User::factory()->reviewer()->create();
});

it('funcionário só vê os próprios pedidos', function () {
    $mine = Reimbursement::factory()->for($this->employee)->create();
    $others = Reimbursement::factory()->create();
    Sanctum::actingAs($this->employee);

    $this->getJson('/api/reimbursements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    $this->getJson("/api/reimbursements/{$mine->id}")->assertOk();
    $this->getJson("/api/reimbursements/{$others->id}")->assertNotFound(); // não revela que existe
});

it('analista vê todos os pedidos e filtra por status', function () {
    Reimbursement::factory()->count(2)->create();
    Reimbursement::factory()->pendingReview()->create();
    Sanctum::actingAs($this->reviewer);

    $this->getJson('/api/reimbursements')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/reimbursements?status=pending_review')->assertOk()->assertJsonCount(1, 'data');
});

it('não aceita ordenação ou filtro fora da lista permitida', function (string $query) {
    Sanctum::actingAs($this->reviewer);

    $this->getJson("/api/reimbursements?{$query}")->assertUnprocessable();
})->with([
    'coluna arbitrária' => ['sort=password'],
    'SQL no sort' => ['sort='.urlencode('created_at; DROP TABLE users')],
    'direção inválida' => ['direction='.urlencode('desc, (select 1)')],
    'status inválido' => ['status='.urlencode("' OR 1=1 --")],
    'página gigante' => ['per_page=100000'],
]);

it('analista aprova um pedido em revisão', function () {
    $r = Reimbursement::factory()->for($this->employee)->pendingReview()->create();
    Sanctum::actingAs($this->reviewer);

    $this->postJson("/api/reimbursements/{$r->id}/review", ['decision' => 'approve'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.review.reviewer_id', $this->reviewer->id);
});

it('recusar exige justificativa', function () {
    $r = Reimbursement::factory()->pendingReview()->create();
    Sanctum::actingAs($this->reviewer);

    $this->postJson("/api/reimbursements/{$r->id}/review", ['decision' => 'reject'])
        ->assertUnprocessable()->assertJsonValidationErrors('note');

    $this->postJson("/api/reimbursements/{$r->id}/review", ['decision' => 'reject', 'note' => 'Nota ilegível'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');
});

it('funcionário não pode revisar', function () {
    $r = Reimbursement::factory()->pendingReview()->create();
    Sanctum::actingAs($this->employee);

    $this->postJson("/api/reimbursements/{$r->id}/review", ['decision' => 'approve'])->assertForbidden();
    expect($r->refresh()->status)->toBe(ReimbursementStatus::PendingReview);
});

it('analista não pode aprovar o próprio pedido', function () {
    $r = Reimbursement::factory()->for($this->reviewer)->pendingReview()->create();
    Sanctum::actingAs($this->reviewer);

    $this->postJson("/api/reimbursements/{$r->id}/review", ['decision' => 'approve'])->assertForbidden();
});

it('não revisa pedido que não está em revisão', function (string $status) {
    $r = Reimbursement::factory()->create(['status' => $status]);
    Sanctum::actingAs($this->reviewer);

    $this->postJson("/api/reimbursements/{$r->id}/review", ['decision' => 'approve'])->assertStatus(409);
})->with(['submitted', 'auto_approved', 'rejected', 'approved']);

it('métricas da IA só aparecem para analistas', function () {
    $r = Reimbursement::factory()->for($this->employee)->pendingReview()->create(['ai_model' => 'claude-haiku-5-5']);

    Sanctum::actingAs($this->employee);
    $this->getJson("/api/reimbursements/{$r->id}")->assertOk()->assertJsonMissingPath('data.ai');

    Sanctum::actingAs($this->reviewer);
    $this->getJson("/api/reimbursements/{$r->id}")->assertOk()->assertJsonPath('data.ai.model', 'claude-haiku-5-5');
});

it('transição inválida responde 409 sem poluir o log de erros', function () {
    Log::spy();
    $r = Reimbursement::factory()->create(['status' => 'approved']);
    Sanctum::actingAs($this->reviewer);

    $this->postJson("/api/reimbursements/{$r->id}/review", ['decision' => 'approve'])->assertStatus(409);

    Log::shouldNotHaveReceived('error');
});
