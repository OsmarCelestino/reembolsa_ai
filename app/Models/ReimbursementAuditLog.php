<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\ReimbursementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro imutável (só insert) do que aconteceu com um pedido e quem fez.
 * Nunca é criado a partir de dados do request: só pelo próprio modelo Reimbursement.
 */
#[Fillable(['action', 'from_status', 'to_status', 'actor_id', 'reason'])]
class ReimbursementAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'from_status' => ReimbursementStatus::class,
            'to_status' => ReimbursementStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Reimbursement, $this> */
    public function reimbursement(): BelongsTo
    {
        return $this->belongsTo(Reimbursement::class);
    }
}
