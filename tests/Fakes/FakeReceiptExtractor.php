<?php

namespace Tests\Fakes;

use App\Ai\Contracts\ReceiptExtractor;
use App\Ai\Data\ExtractedReceipt;
use App\Ai\Data\ExtractionResult;
use Throwable;

final class FakeReceiptExtractor implements ReceiptExtractor
{
    public int $calls = 0;

    public function __construct(
        private ?ExtractedReceipt $receipt = null,
        private ?Throwable $throw = null,
    ) {}

    public function extract(string $contents, string $mimeType): ExtractionResult
    {
        $this->calls++;

        if ($this->throw) {
            throw $this->throw;
        }

        return new ExtractionResult($this->receipt ?? receipt(), 'fake-model', 1200, 80, 950);
    }
}
