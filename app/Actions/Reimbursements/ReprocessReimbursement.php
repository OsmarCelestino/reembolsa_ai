<?php

namespace App\Actions\Reimbursements;

use App\Domain\Reimbursement\InvalidTransition;
use App\Enums\ReimbursementStatus;
use App\Jobs\ProcessReimbursementJob;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** failed → submitted: devolve à fila um pedido cujas tentativas se esgotaram (ex.: IA fora do ar por horas). */
final class ReprocessReimbursement
{
    /** @throws InvalidTransition se o pedido não estiver em failed (HTTP 409) */
    public function handle(Reimbursement $reimbursement, ?User $actor = null): Reimbursement
    {
        return DB::transaction(function () use ($reimbursement, $actor) {
            $locked = Reimbursement::query()->lockForUpdate()->findOrFail($reimbursement->id);

            if ($locked->status !== ReimbursementStatus::Failed) {
                throw InvalidTransition::cannotReprocess($locked->status);
            }

            $locked->transitionTo(ReimbursementStatus::Submitted, $actor, reason: 'reprocess');

            ProcessReimbursementJob::dispatch($locked->id)->afterCommit();

            return $locked;
        });
    }
}
