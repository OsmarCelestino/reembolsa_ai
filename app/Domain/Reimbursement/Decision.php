<?php

namespace App\Domain\Reimbursement;

use App\Enums\ReimbursementStatus;
use App\Enums\Severity;

/** Resultado de ReimbursementRules::evaluate(): a lista de violações e o status que ela implica. */
final readonly class Decision
{
    /** @param list<Violation> $violations */
    public function __construct(public array $violations) {}

    /** Qualquer hard → rejected; senão qualquer soft → pending_review; sem violações → auto_approved. */
    public function status(): ReimbursementStatus
    {
        if ($this->has(Severity::Hard)) {
            return ReimbursementStatus::Rejected;
        }

        return $this->has(Severity::Soft)
            ? ReimbursementStatus::PendingReview
            : ReimbursementStatus::AutoApproved;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(fn (Violation $v) => $v->code, $this->violations);
    }

    /** @return list<array{code: string, severity: string, message: string}> */
    public function toArray(): array
    {
        return array_map(fn (Violation $v) => $v->toArray(), $this->violations);
    }

    private function has(Severity $severity): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->severity === $severity) {
                return true;
            }
        }

        return false;
    }
}
