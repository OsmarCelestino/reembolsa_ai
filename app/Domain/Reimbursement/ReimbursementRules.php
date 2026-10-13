<?php

namespace App\Domain\Reimbursement;

use App\Ai\Data\ExtractedReceipt;
use App\Enums\ExpenseCategory;
use App\Support\Cnpj;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Regras de negócio do reembolso. Código puro: não acessa banco, HTTP nem IA,
 * por isso é fácil de testar e de explicar.
 *
 * Violação "hard" recusa o pedido; "soft" manda para um analista.
 */
final class ReimbursementRules
{
    public function __construct(
        private readonly int $maxAgeDays,
        private readonly int $autoApproveMaxCents,
        private readonly float $minConfidence,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxAgeDays: (int) config('reimbursement.receipt_max_age_days'),
            autoApproveMaxCents: (int) config('reimbursement.auto_approve_max_cents'),
            minConfidence: (float) config('reimbursement.min_confidence'),
        );
    }

    /** @param  CarbonImmutable|null  $today  injetável para os testes não dependerem da data atual */
    public function evaluate(
        ExpenseCategory $declaredCategory,
        int $claimedCents,
        ExtractedReceipt $receipt,
        bool $isDuplicate,
        ?CarbonImmutable $today = null,
    ): Decision {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $v = [];

        // --- Segurança / qualidade da extração ---
        if ($receipt->suspiciousContent) {
            $v[] = Violation::soft('suspicious_content', 'O comprovante tem conteúdo suspeito (possível tentativa de manipular a IA).');
        }

        if ($receipt->confidence < $this->minConfidence) {
            $v[] = Violation::soft('low_confidence', "Confiança da extração ({$receipt->confidence}) abaixo do mínimo ({$this->minConfidence}).");
        }

        // --- Dados obrigatórios ---
        if ($receipt->totalCents === null || $receipt->issueDate === null || $receipt->cnpj === null) {
            $v[] = Violation::soft('missing_fields', 'Não foi possível ler CNPJ, data ou valor total do comprovante.');
        }

        if ($receipt->cnpj !== null && ! Cnpj::isValid($receipt->cnpj)) {
            $v[] = Violation::soft('invalid_cnpj', 'O CNPJ lido não é válido.');
        }

        // --- Regras de negócio ---
        if ($isDuplicate) {
            $v[] = Violation::hard('duplicate_receipt', 'Este comprovante já foi usado em outro pedido.');
        }

        if ($receipt->issueDate !== null) {
            if ($receipt->issueDate->greaterThan($today)) {
                $v[] = Violation::hard('receipt_in_future', 'A data do comprovante está no futuro.');
            } elseif ($receipt->issueDate->lessThan($today->subDays($this->maxAgeDays))) {
                $v[] = Violation::hard('receipt_expired', "O comprovante tem mais de {$this->maxAgeDays} dias.");
            }
        }

        if ($receipt->totalCents !== null && $claimedCents > $receipt->totalCents) {
            $v[] = Violation::hard('claimed_exceeds_receipt', 'O valor pedido ('.Money::format($claimedCents).') é maior que o do comprovante ('.Money::format($receipt->totalCents).').');
        }

        if ($claimedCents > $declaredCategory->limitCents()) {
            $v[] = Violation::soft('exceeds_category_limit', 'O valor passa do limite da categoria ('.Money::format($declaredCategory->limitCents()).').');
        }

        if ($receipt->category !== null && $receipt->category !== $declaredCategory) {
            $v[] = Violation::soft('category_mismatch', "A categoria informada ({$declaredCategory->value}) difere da identificada no comprovante ({$receipt->category->value}).");
        }

        if ($claimedCents > $this->autoApproveMaxCents) {
            $v[] = Violation::soft('above_auto_approval_limit', 'Valor acima do limite de aprovação automática.');
        }

        return new Decision($v);
    }
}
