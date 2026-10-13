<?php

namespace App\Jobs;

use App\Webhooks\WebhookSignature;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Entrega um webhook com assinatura e novas tentativas. Falha aqui nunca afeta o pedido. */
class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $url,
        public readonly string $event,
        public readonly string $deliveryId,
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        $body = (string) json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        $response = Http::timeout((int) config('webhooks.timeout'))
            ->withHeaders([
                'X-Reembolsa-Event' => $this->event,
                'X-Reembolsa-Delivery' => $this->deliveryId, // o receptor usa para ignorar entregas repetidas
                'X-Reembolsa-Timestamp' => (string) $timestamp,
                'X-Reembolsa-Signature' => WebhookSignature::sign((string) config('webhooks.secret'), $timestamp, $body),
            ])
            ->withBody($body, 'application/json')
            ->post($this->url);

        if (! $response->successful()) {
            throw new RuntimeException("Webhook respondeu HTTP {$response->status()}.");
        }
    }
}
