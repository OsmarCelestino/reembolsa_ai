<?php

namespace App\Ai\Data;

/** Dados extraídos e métricas da chamada (modelo, tokens, latência), gravados para auditoria e custo. */
final readonly class ExtractionResult
{
    public function __construct(
        public ExtractedReceipt $receipt,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
    ) {}
}
