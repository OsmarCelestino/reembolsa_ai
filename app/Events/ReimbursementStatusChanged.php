<?php

namespace App\Events;

use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Disparado só depois do commit: ninguém é notificado de uma mudança que acabou desfeita. */
final class ReimbursementStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Reimbursement $reimbursement,
        public readonly ?ReimbursementStatus $from,
        public readonly ReimbursementStatus $to,
    ) {}
}
