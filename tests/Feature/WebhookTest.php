<?php

use App\Actions\Reimbursements\ReviewReimbursement;
use App\Enums\ReimbursementStatus;
use App\Jobs\DeliverWebhookJob;
use App\Models\Reimbursement;
use App\Models\User;
use App\Webhooks\WebhookSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'webhooks.url' => 'https://erp.example.com/hooks/reembolso',
        'webhooks.secret' => 'segredo-de-teste',
    ]);
});

function approvePending(): Reimbursement
{
    $r = Reimbursement::factory()->pendingReview()->create();

    return app(ReviewReimbursement::class)->handle(User::factory()->reviewer()->create(), $r, true, null);
}

it('enfileira o webhook quando o pedido chega a um status final, sem dados pessoais', function () {
    Queue::fake();

    $r = approvePending();

    Queue::assertPushed(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($r) {
        return $job->url === 'https://erp.example.com/hooks/reembolso'
            && $job->payload['reimbursement_id'] === $r->id
            && $job->payload['previous_status'] === 'pending_review'
            && $job->payload['status'] === 'approved'
            && ! array_key_exists('description', $job->payload)
            && ! array_key_exists('extracted', $job->payload)
            && ! str_contains(json_encode($job->payload), $r->user->email);
    });
});

it('não notifica status intermediários', function () {
    Queue::fake();
    $r = Reimbursement::factory()->create(['status' => ReimbursementStatus::Submitted]);

    $r->transitionTo(ReimbursementStatus::Processing);

    Queue::assertNotPushed(DeliverWebhookJob::class);
});

it('não envia nada sem URL, com http ou sem segredo', function (array $config) {
    Queue::fake();
    config($config);

    approvePending();

    Queue::assertNotPushed(DeliverWebhookJob::class);
})->with([
    'sem URL' => [['webhooks.url' => null]],
    'http' => [['webhooks.url' => 'http://erp.example.com/hook']],
    'sem segredo' => [['webhooks.secret' => null]],
    'URL inválida' => [['webhooks.url' => 'https://']],
]);

it('não notifica se a transação for desfeita', function () {
    Queue::fake();
    $r = Reimbursement::factory()->pendingReview()->create();

    try {
        DB::transaction(function () use ($r) {
            $r->transitionTo(ReimbursementStatus::Approved);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNotPushed(DeliverWebhookJob::class);
});

it('entrega com assinatura que o receptor consegue validar', function () {
    Http::fake(['erp.example.com/*' => Http::response(status: 204)]);

    (new DeliverWebhookJob('https://erp.example.com/hooks/reembolso', 'reimbursement.status_changed', 'd-1', ['status' => 'approved']))->handle();

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('X-Reembolsa-Delivery', 'd-1')
            && WebhookSignature::verify(
                'segredo-de-teste',
                $request->header('X-Reembolsa-Timestamp')[0],
                $request->body(),
                $request->header('X-Reembolsa-Signature')[0],
            );
    });
});

it('lança erro para a fila tentar de novo quando o receptor falha', function () {
    Http::fake(['*' => Http::response(status: 503)]);

    (new DeliverWebhookJob('https://erp.example.com/h', 'e', 'd', []))->handle();
})->throws(RuntimeException::class, 'HTTP 503');

it('assinatura recusa corpo alterado, segredo errado e timestamp velho', function () {
    $ts = 1_700_000_000;
    $sig = WebhookSignature::sign('s', $ts, '{"status":"approved"}');

    expect(WebhookSignature::verify('s', (string) $ts, '{"status":"approved"}', $sig, now: $ts))->toBeTrue()
        ->and(WebhookSignature::verify('s', (string) $ts, '{"status":"rejected"}', $sig, now: $ts))->toBeFalse()
        ->and(WebhookSignature::verify('outro', (string) $ts, '{"status":"approved"}', $sig, now: $ts))->toBeFalse()
        ->and(WebhookSignature::verify('s', (string) $ts, '{"status":"approved"}', $sig, now: $ts + 301))->toBeFalse()
        ->and(WebhookSignature::verify('s', 'abc', '{"status":"approved"}', $sig, now: $ts))->toBeFalse();
});
