<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Alimentacao = 'alimentacao';
    case Transporte = 'transporte';
    case Hospedagem = 'hospedagem';
    case Material = 'material';

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
