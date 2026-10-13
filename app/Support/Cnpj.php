<?php

namespace App\Support;

/**
 * Validação de CNPJ, incluindo o formato alfanumérico (Receita Federal, a partir de jul/2026):
 * as 12 primeiras posições aceitam 0-9 e A-Z; os 2 dígitos verificadores continuam numéricos.
 */
final class Cnpj
{
    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[.\-\/\s]/', '', $value));
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $cnpj = self::normalize($value);

        if (! preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $cnpj)) {
            return false;
        }

        // Sequências repetidas (00000000000000, 11111111111111...) passam no cálculo mas são inválidas.
        if (preg_match('/^(.)\1{13}$/', $cnpj)) {
            return false;
        }

        $base = substr($cnpj, 0, 12);
        $first = self::checkDigit($base);
        $second = self::checkDigit($base.$first);

        return substr($cnpj, 12, 2) === $first.$second;
    }

    public static function format(string $value): string
    {
        $c = self::normalize($value);

        return sprintf('%s.%s.%s/%s-%s', substr($c, 0, 2), substr($c, 2, 3), substr($c, 5, 3), substr($c, 8, 4), substr($c, 12, 2));
    }

    private static function checkDigit(string $digits): string
    {
        $weight = strlen($digits) - 7; // 5 para 12 posições, 6 para 13
        $sum = 0;

        foreach (str_split($digits) as $char) {
            $sum += (ord($char) - 48) * $weight;
            $weight = $weight === 2 ? 9 : $weight - 1;
        }

        $rest = $sum % 11;

        return (string) ($rest < 2 ? 0 : 11 - $rest);
    }
}
