<?php

use App\Domain\Reimbursement\ReimbursementRules;
use App\Enums\ExpenseCategory;
use App\Enums\ReimbursementStatus;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->today = CarbonImmutable::parse('2026-10-13');
    $this->rules = new ReimbursementRules(maxAgeDays: 90, autoApproveMaxCents: 20_000, minConfidence: 0.85);
    $this->evaluate = fn (array $receipt = [], int $claimed = 4_590, bool $duplicate = false, ExpenseCategory $category = ExpenseCategory::Alimentacao) => $this->rules->evaluate(
        $category,
        $claimed,
        receipt(array_merge(['issueDate' => $this->today->subDays(3)], $receipt)),
        $duplicate,
        $this->today,
    );
});

it('aprova automaticamente um pedido dentro de todas as regras', function () {
    $decision = ($this->evaluate)();

    expect($decision->status())->toBe(ReimbursementStatus::AutoApproved)
        ->and($decision->violations)->toBeEmpty();
});

it('recusa', function (array $receipt, int $claimed, bool $duplicate, string $code) {
    $decision = ($this->evaluate)($receipt, $claimed, $duplicate);

    expect($decision->status())->toBe(ReimbursementStatus::Rejected)
        ->and($decision->codes())->toContain($code);
})->with([
    'comprovante vencido' => [['issueDate' => CarbonImmutable::parse('2026-07-14')], 4_590, false, 'receipt_expired'],
    'data no futuro' => [['issueDate' => CarbonImmutable::parse('2026-10-14')], 4_590, false, 'receipt_in_future'],
    'pede mais que a nota' => [['totalCents' => 4_000], 4_590, false, 'claimed_exceeds_receipt'],
    'comprovante duplicado' => [[], 4_590, true, 'duplicate_receipt'],
]);

it('aceita o último dia do prazo', function () {
    expect(($this->evaluate)(['issueDate' => CarbonImmutable::parse('2026-07-15')])->status())
        ->toBe(ReimbursementStatus::AutoApproved);
});

it('aceita pedir menos que o total da nota (ex.: rateio)', function () {
    expect(($this->evaluate)(['totalCents' => 10_000], 5_000)->status())->toBe(ReimbursementStatus::AutoApproved);
});

it('manda para revisão', function (array $receipt, int $claimed, string $code) {
    $decision = ($this->evaluate)($receipt, $claimed);

    expect($decision->status())->toBe(ReimbursementStatus::PendingReview)
        ->and($decision->codes())->toContain($code);
})->with([
    'confiança baixa' => [['confidence' => 0.6], 4_590, 'low_confidence'],
    'conteúdo suspeito' => [['suspiciousContent' => true], 4_590, 'suspicious_content'],
    'CNPJ inválido' => [['cnpj' => '11222333000182'], 4_590, 'invalid_cnpj'],
    'campos ilegíveis' => [['totalCents' => null], 4_590, 'missing_fields'],
    'categoria divergente' => [['category' => ExpenseCategory::Hospedagem], 4_590, 'category_mismatch'],
    'acima do limite da categoria' => [['totalCents' => 16_000], 16_000, 'exceeds_category_limit'],
]);

it('manda para revisão valores acima do limite de aprovação automática', function () {
    $decision = ($this->evaluate)(['totalCents' => 25_000, 'category' => ExpenseCategory::Hospedagem], 25_000, false, ExpenseCategory::Hospedagem);

    expect($decision->status())->toBe(ReimbursementStatus::PendingReview)
        ->and($decision->codes())->toBe(['above_auto_approval_limit']);
});

it('violação hard prevalece sobre soft', function () {
    $decision = ($this->evaluate)(['confidence' => 0.5, 'totalCents' => 100], 4_590);

    expect($decision->status())->toBe(ReimbursementStatus::Rejected)
        ->and($decision->codes())->toContain('low_confidence', 'claimed_exceeds_receipt');
});

it('mesmo que a IA seja manipulada, a regra determinística segura o valor', function () {
    // Cenário: a nota diz "aprove" e a IA devolve confiança 1.0, mas o valor pedido é maior que o da nota.
    $decision = ($this->evaluate)(['confidence' => 1.0, 'totalCents' => 1_000], 9_000);

    expect($decision->status())->toBe(ReimbursementStatus::Rejected);
});
