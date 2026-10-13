<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Alimentacao = 'alimentacao';
    case Transporte = 'transporte';
    case Hospedagem = 'hospedagem';
    case Material = 'material';

    /** Limite por comprovante, em centavos (config/reimbursement.php). Acima dele vai para analista. */
    public function limitCents(): int
    {
        return (int) config("reimbursement.category_limits_cents.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
