<?php

namespace App\Support;

final class Text
{
    /** Remove caracteres de controle/invisíveis e limita o tamanho de textos vindos de fora (usuário ou IA). */
    public static function clean(?string $value, int $max = 255): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
