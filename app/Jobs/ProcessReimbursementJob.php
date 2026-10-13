<?php

namespace App\Jobs;

use App\Actions\Reimbursements\ProcessReimbursement;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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

    public function failed(?Throwable $exception): void
    {
        Reimbursement::query()
            ->whereKey($this->reimbursementId)
            ->whereIn('status', [ReimbursementStatus::Submitted, ReimbursementStatus::Processing])
            ->update(['status' => ReimbursementStatus::Failed]);
    }
}
