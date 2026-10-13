<?php

namespace App\Actions\Reimbursements;

use App\Ai\Contracts\ReceiptExtractor;
use App\Ai\Exceptions\AiProviderUnavailable;
use App\Ai\Exceptions\ExtractionFailed;
use App\Domain\Reimbursement\ReimbursementRules;
use App\Domain\Reimbursement\Violation;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Orquestra o processamento: lê o arquivo, chama a IA para EXTRAIR dados
 * e aplica as regras de negócio (código determinístico) para DECIDIR.
 */
final class ProcessReimbursement
{
    public function __construct(
        private readonly ReceiptExtractor $extractor,
        private readonly ReimbursementRules $rules,
    ) {}

    public function handle(int $reimbursementId): void
    {
        $reimbursement = $this->claim($reimbursementId);

        if ($reimbursement === null) {
            return; // já processado ou inexistente: idempotente
        }

        try {
            $result = $this->extractor->extract(
                Storage::disk(config('reimbursement.upload.disk'))->get($reimbursement->receipt_path),
                $reimbursement->receipt_mime,
            );
        } catch (ExtractionFailed $e) {
            Log::warning('Extração do comprovante falhou', ['reimbursement_id' => $reimbursement->id, 'reason' => $e->getMessage()]);

            $reimbursement->forceFill([
                'status' => ReimbursementStatus::PendingReview,
                'violations' => [Violation::soft('extraction_failed', 'Não foi possível ler o comprovante automaticamente.')->toArray()],
                'processed_at' => now(),
            ])->save();

            return;
        } catch (AiProviderUnavailable $e) {
            // Volta para a fila: o job tenta de novo com backoff.
            $reimbursement->forceFill(['status' => ReimbursementStatus::Submitted])->save();

            throw $e;
        }

        $receipt = $result->receipt;
        $fingerprint = $receipt->fingerprint();

        $isDuplicate = $fingerprint !== null && Reimbursement::query()
            ->where('receipt_fingerprint', $fingerprint)
            ->whereKeyNot($reimbursement->id)
            ->where('status', '!=', ReimbursementStatus::Rejected)
            ->exists();

        $decision = $this->rules->evaluate(
            declaredCategory: $reimbursement->category,
            claimedCents: $reimbursement->claimed_amount_cents,
            receipt: $receipt,
            isDuplicate: $isDuplicate,
        );

        $reimbursement->forceFill([
            'status' => $decision->status(),
            'extracted' => $receipt->toArray(),
            'receipt_fingerprint' => $fingerprint,
            'ai_confidence' => $receipt->confidence,
            'ai_model' => $result->model,
            'ai_input_tokens' => $result->inputTokens,
            'ai_output_tokens' => $result->outputTokens,
            'ai_latency_ms' => $result->latencyMs,
            'violations' => $decision->toArray(),
            'processed_at' => now(),
        ])->save();

        Log::info('Reembolso processado', [
            'reimbursement_id' => $reimbursement->id,
            'status' => $decision->status()->value,
            'violations' => $decision->codes(),
            'model' => $result->model,
            'latency_ms' => $result->latencyMs,
        ]);
    }

    /** Trava a linha e muda para "processing" só se ainda estiver "submitted". */
    private function claim(int $id): ?Reimbursement
    {
        return DB::transaction(function () use ($id) {
            $reimbursement = Reimbursement::query()->lockForUpdate()->find($id);

            if ($reimbursement?->status !== ReimbursementStatus::Submitted) {
                return null;
            }

            $reimbursement->forceFill(['status' => ReimbursementStatus::Processing])->save();

            return $reimbursement;
        });
    }
}
