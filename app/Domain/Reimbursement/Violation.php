<?php

namespace App\Domain\Reimbursement;

use App\Enums\Severity;

/**
 * Uma regra que o pedido violou. Hard recusa, soft manda para analista.
 * O `code` é estável (testes e clientes da API dependem dele); a `message` é para pessoas.
 */
final readonly class Violation
{
    public function __construct(
        public string $code,
        public Severity $severity,
        public string $message,
    ) {}

    public static function hard(string $code, string $message): self
    {
        return new self($code, Severity::Hard, $message);
    }

    public static function soft(string $code, string $message): self
    {
        return new self($code, Severity::Soft, $message);
    }

    /** @return array{code: string, severity: string, message: string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'severity' => $this->severity->value, 'message' => $this->message];
    }
}
