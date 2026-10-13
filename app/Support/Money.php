<?php

namespace App\Support;

use InvalidArgumentException;

/** Conversão de valores em reais (string) para centavos sem passar por float. */
final class Money
{
    public const PATTERN = '/^\d{1,7}([.,]\d{1,2})?$/';

    /**
     * Aceita "123", "123.4" e "123,45" (sem separador de milhar).
     *
     * @throws InvalidArgumentException fora desse formato
     */
    public static function toCents(string $amount): int
    {
        $amount = trim($amount);

        if (! preg_match(self::PATTERN, $amount)) {
            throw new InvalidArgumentException('Valor monetário inválido.');
        }

        [$reais, $centavos] = array_pad(preg_split('/[.,]/', $amount), 2, '0');

        return ((int) $reais * 100) + (int) str_pad($centavos, 2, '0');
    }

    /** 4590 → "R$ 45,90". */
    public static function format(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
