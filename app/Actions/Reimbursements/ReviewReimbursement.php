<?php

namespace App\Actions\Reimbursements;

use App\Domain\Reimbursement\InvalidTransition;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use App\Models\User;
use App\Support\Text;
use Illuminate\Support\Facades\DB;

/**
 * Decisão do analista sobre um pedido em revisão: pending_review → approved | rejected.
 * Quem pode revisar é checado antes, na ReimbursementPolicy.
 */
final class ReviewReimbursement
{
    /**
     * Trava a linha para duas revisões simultâneas não se sobreporem.
     *
     * @throws InvalidTransition se o pedido não estiver em pending_review (HTTP 409)
     */
    public function handle(User $reviewer, Reimbursement $reimbursement, bool $approve, ?string $note): Reimbursement
    {
        return DB::transaction(function () use ($reviewer, $reimbursement, $approve, $note) {
            $locked = Reimbursement::query()->lockForUpdate()->findOrFail($reimbursement->id);

            if ($locked->status !== ReimbursementStatus::PendingReview) {
                throw InvalidTransition::cannotReview($locked->status);
            }

            $locked->forceFill([
                'status' => $approve ? ReimbursementStatus::Approved : ReimbursementStatus::Rejected,
                'reviewer_id' => $reviewer->id,
                'review_note' => Text::clean($note, 1000),
                'reviewed_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
