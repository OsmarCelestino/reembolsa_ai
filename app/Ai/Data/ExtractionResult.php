<?php

namespace App\Ai\Data;

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
