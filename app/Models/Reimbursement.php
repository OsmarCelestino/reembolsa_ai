<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\ExpenseCategory;
use App\Enums\ReimbursementStatus;
use App\Events\ReimbursementStatusChanged;
use Database\Factories\ReimbursementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
            'receipt_purged_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // LGPD: ao excluir o pedido (ou a conta do usuário, em cascata pela aplicação), o arquivo vai junto.
        static::deleted(function (Reimbursement $reimbursement) {
            Storage::disk(config('reimbursement.upload.disk'))->delete($reimbursement->receipt_path);
        });
    }

    /**
     * Único caminho para mudar o status: grava o novo estado, registra na auditoria
     * e avisa quem precisa saber (webhook), tudo na mesma transação.
     *
     * @param  array<string, mixed>  $attributes  outros campos internos a gravar junto
     */
    public function transitionTo(ReimbursementStatus $to, ?User $actor = null, array $attributes = [], ?string $reason = null): void
    {
        DB::transaction(function () use ($to, $actor, $attributes, $reason) {
            $from = $this->exists ? $this->getOriginal('status') : null;

            $this->forceFill([...$attributes, 'status' => $to])->save();

            $this->audit(AuditAction::StatusChanged, $actor, $reason, $from, $to);

            ReimbursementStatusChanged::dispatch($this, $from, $to);
        });
    }

    public function audit(
        AuditAction $action,
        ?User $actor = null,
        ?string $reason = null,
        ?ReimbursementStatus $from = null,
        ?ReimbursementStatus $to = null,
    ): ReimbursementAuditLog {
        return $this->auditLogs()->create([
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor?->id,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 100),
        ]);
    }

    /** @return HasMany<ReimbursementAuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(ReimbursementAuditLog::class)->orderBy('id');
    }

    public function receiptAvailable(): bool
    {
        return $this->receipt_purged_at === null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Analista vê todos os pedidos; funcionário, só os seus.
     *
     * @param  Builder<Reimbursement>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isReviewer()) {
            $query->whereBelongsTo($user);
        }
    }
}
