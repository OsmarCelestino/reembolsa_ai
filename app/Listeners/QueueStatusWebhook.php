<?php

namespace App\Listeners;

use App\Events\ReimbursementStatusChanged;
use App\Jobs\DeliverWebhookJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Transforma a mudança de status em uma entrega de webhook na fila. */
class QueueStatusWebhook
{
    public function handle(ReimbursementStatusChanged $event): void
    {
        $url = config('webhooks.url');

        if (blank($url) || ! in_array($event->to->value, config('webhooks.statuses'), true)) {
            return;
        }

        if (! $this->isAllowedUrl((string) $url) || blank(config('webhooks.secret'))) {
            Log::error('Webhook ignorado: WEBHOOK_URL precisa ser https (ou WEBHOOK_ALLOW_HTTP=true) e WEBHOOK_SECRET precisa estar definido.');

            return;
        }

        $r = $event->reimbursement;

        // Sem nome, e-mail, descrição ou dados da nota: só o necessário para o outro sistema reagir.
        DeliverWebhookJob::dispatch($url, 'reimbursement.status_changed', (string) Str::uuid(), [
            'event' => 'reimbursement.status_changed',
            'reimbursement_id' => $r->id,
            'user_id' => $r->user_id,
            'category' => $r->category->value,
            'claimed_amount_cents' => $r->claimed_amount_cents,
            'previous_status' => $event->from?->value,
            'status' => $event->to->value,
            'occurred_at' => now()->toIso8601String(),
        ]);
    }

    private function isAllowedUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && ($scheme === 'https' || ($scheme === 'http' && config('webhooks.allow_http')));
    }
}
