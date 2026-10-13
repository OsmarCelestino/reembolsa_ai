<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\ReimbursementStatus;
use Database\Factories\ReimbursementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Só os campos informados pelo usuário são "fillable". Status, resultado da IA
 * e revisão só mudam pelas Actions (forceFill), nunca por mass assignment.
 */
#[Fillable(['category', 'claimed_amount_cents', 'description'])]
#[Hidden(['receipt_path', 'receipt_sha256', 'receipt_fingerprint'])]
class Reimbursement extends Model
{
    /** @use HasFactory<ReimbursementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'status' => ReimbursementStatus::class,
            'claimed_amount_cents' => 'integer',
            'extracted' => 'array',
            'violations' => 'array',
            'ai_confidence' => 'float',
            'processed_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @param Builder<Reimbursement> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isReviewer()) {
            $query->whereBelongsTo($user);
        }
    }
}
