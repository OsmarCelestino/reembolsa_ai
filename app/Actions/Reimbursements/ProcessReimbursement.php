<?php

namespace App\Actions\Reimbursements;

use App\Ai\Contracts\ReceiptExtractor;
use App\Ai\Data\ExtractionResult;
use App\Ai\Exceptions\AiProviderUnavailable;
use App\Ai\Exceptions\ExtractionFailed;
use App\Domain\Reimbursement\Decision;
use App\Domain\Reimbursement\ReimbursementRules;
use App\Domain\Reimbursement\Violation;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use Illuminate\Database\UniqueConstraintViolationException;
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
            $contents = Storage::disk(config('reimbursement.upload.disk'))->get($reimbursement->receipt_path)
                ?? throw new ExtractionFailed('Arquivo do comprovante não encontrado no disco.');

            $result = $this->extractor->extract($contents, $reimbursement->receipt_mime);
        } catch (ExtractionFailed $e) {
            Log::warning('Extração do comprovante falhou', ['reimbursement_id' => $reimbursement->id, 'reason' => $e->getMessage()]);

            $reimbursement->transitionTo(ReimbursementStatus::PendingReview, attributes: [
                'violations' => [Violation::soft('extraction_failed', 'Não foi possível ler o comprovante automaticamente.')->toArray()],
                'processed_at' => now(),
            ], reason: 'extraction_failed');

            return;
        } catch (AiProviderUnavailable $e) {
            Log::warning('IA indisponível, pedido volta para a fila', ['reimbursement_id' => $reimbursement->id, 'reason' => $e->getMessage()]);

            // Volta para a fila: o job tenta de novo com backoff.
            $reimbursement->transitionTo(ReimbursementStatus::Submitted, reason: 'ai_unavailable');

            throw $e;
        }

        $fingerprint = $result->receipt->fingerprint();

        // 1ª checagem (informativa): já existe outro pedido ativo com o mesmo comprovante?
        $isDuplicate = $fingerprint !== null && Reimbursement::query()
            ->where('receipt_fingerprint', $fingerprint)
            ->whereKeyNot($reimbursement->id)
            ->where('status', '!=', ReimbursementStatus::Rejected)
            ->exists();

        try {
            $decision = $this->decide($reimbursement, $result, $isDuplicate);
        } catch (UniqueConstraintViolationException) {
            // 2ª checagem (garantia): outro pedido com o mesmo comprovante foi gravado entre a
            // consulta acima e o save. O índice único do banco barrou; decide de novo como duplicado.
            $decision = $this->decide($reimbursement->refresh(), $result, isDuplicate: true);
        }

        Log::info('Reembolso processado', [
            'reimbursement_id' => $reimbursement->id,
            'status' => $decision->status()->value,
            'violations' => $decision->codes(),
            'model' => $result->model,
            'latency_ms' => $result->latencyMs,
        ]);
    }

    private function decide(Reimbursement $reimbursement, ExtractionResult $result, bool $isDuplicate): Decision
    {
        $receipt = $result->receipt;

        $decision = $this->rules->evaluate(
            declaredCategory: $reimbursement->category,
            claimedCents: $reimbursement->claimed_amount_cents,
            receipt: $receipt,
            isDuplicate: $isDuplicate,
        );

        $reimbursement->transitionTo($decision->status(), attributes: [
            'extracted' => $receipt->toArray(),
            'receipt_fingerprint' => $receipt->fingerprint(),
            'ai_confidence' => $receipt->confidence,
            'ai_model' => $result->model,
            'ai_input_tokens' => $result->inputTokens,
            'ai_output_tokens' => $result->outputTokens,
            'ai_latency_ms' => $result->latencyMs,
            'violations' => $decision->toArray(),
            'processed_at' => now(),
        ], reason: 'ai_decision');

        return $decision;
    }

    /** Trava a linha e muda para "processing" só se ainda estiver "submitted". */
    private function claim(int $id): ?Reimbursement
    {
        return DB::transaction(function () use ($id) {
            $reimbursement = Reimbursement::query()->lockForUpdate()->find($id);

            if ($reimbursement?->status !== ReimbursementStatus::Submitted) {
                return null;
            }

            $reimbursement->transitionTo(ReimbursementStatus::Processing);

            return $reimbursement;
        });
    }
}
