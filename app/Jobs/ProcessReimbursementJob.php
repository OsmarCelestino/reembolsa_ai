<?php

namespace App\Jobs;

use App\Actions\Reimbursements\ProcessReimbursement;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processa o pedido na fila. Falhas temporárias da IA (AiProviderUnavailable) são relançadas
 * para o job tentar de novo, com espera crescente entre as tentativas.
 */
class ProcessReimbursementJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $reimbursementId) {}

    public function handle(ProcessReimbursement $action): void
    {
        $action->handle($this->reimbursementId);
    }

    /** Esgotadas as tentativas, marca como failed, mas só se o pedido ainda não tiver resultado. */
    public function failed(?Throwable $exception): void
    {
        $reimbursement = Reimbursement::query()
            ->whereKey($this->reimbursementId)
            ->whereIn('status', [ReimbursementStatus::Submitted, ReimbursementStatus::Processing])
            ->first();

        $reimbursement?->transitionTo(ReimbursementStatus::Failed, reason: 'retries_exhausted');
    }
}
