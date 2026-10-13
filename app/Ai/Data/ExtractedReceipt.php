<?php

namespace App\Ai\Data;

use App\Enums\ExpenseCategory;
use Carbon\CarbonImmutable;

/** Dados do comprovante já validados e tipados. Nada aqui é texto livre da IA sem limpeza. */
final readonly class ExtractedReceipt
{
    public function __construct(
        public ?string $cnpj,
        public ?string $issuerName,
        public ?CarbonImmutable $issueDate,
        public ?int $totalCents,
        public ?ExpenseCategory $category,
        public float $confidence,
        public bool $suspiciousContent,
    ) {}

    /** Identifica o mesmo comprovante mesmo que o arquivo seja outro (foto x PDF). */
    public function fingerprint(): ?string
    {
        if ($this->cnpj === null || $this->issueDate === null || $this->totalCents === null) {
            return null;
        }

        return hash('sha256', implode('|', [$this->cnpj, $this->issueDate->toDateString(), $this->totalCents]));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cnpj' => $this->cnpj,
            'issuer_name' => $this->issuerName,
            'issue_date' => $this->issueDate?->toDateString(),
            'total_cents' => $this->totalCents,
            'category' => $this->category?->value,
            'confidence' => $this->confidence,
            'suspicious_content' => $this->suspiciousContent,
        ];
    }
}
