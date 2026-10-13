<?php

namespace App\Http\Resources;

use App\Models\Reimbursement;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Reimbursement */
class ReimbursementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isReviewer = (bool) $request->user()?->isReviewer();

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'category' => $this->category->value,
            'claimed_amount_cents' => $this->claimed_amount_cents,
            'claimed_amount' => Money::format($this->claimed_amount_cents),
            'description' => $this->description,
            'status' => $this->status->value,
            'violations' => $this->violations ?? [],
            'extracted' => $this->extracted,
            // Métricas da IA só para analistas (custo e modelo são dados internos).
            'ai' => $this->when($isReviewer && $this->processed_at !== null, fn () => [
                'model' => $this->ai_model,
                'confidence' => $this->ai_confidence,
                'input_tokens' => $this->ai_input_tokens,
                'output_tokens' => $this->ai_output_tokens,
                'latency_ms' => $this->ai_latency_ms,
            ]),
            'review' => $this->when($this->reviewed_at !== null, fn () => [
                'reviewer_id' => $this->reviewer_id,
                'note' => $this->review_note,
                'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            ]),
            // Linha do tempo (só no detalhe): quem mudou o quê e quando, e quem abriu o comprovante.
            'history' => $this->whenLoaded('auditLogs', fn () => $this->auditLogs->map(fn ($log) => [
                'action' => $log->action->value,
                'from' => $log->from_status?->value,
                'to' => $log->to_status?->value,
                'actor_id' => $log->actor_id,
                'reason' => $log->reason,
                'at' => $log->created_at->toIso8601String(),
            ])->all()),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
