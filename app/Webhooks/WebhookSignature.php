<?php

namespace App\Webhooks;

/**
 * Assinatura HMAC-SHA256 de "<timestamp>.<corpo>". O timestamp assinado impede
 * reaproveitar uma requisição antiga (replay); o receptor usa verify() ou o equivalente.
 */
final class WebhookSignature
{
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    public static function verify(string $secret, string $timestampHeader, string $body, string $signatureHeader, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        if (! ctype_digit($timestampHeader) || $secret === '') {
            return false;
        }

        $timestamp = (int) $timestampHeader;

        if (abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }

        // hash_equals: comparação em tempo constante.
        return hash_equals(self::sign($secret, $timestamp, $body), $signatureHeader);
    }
}
