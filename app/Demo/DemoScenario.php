<?php

namespace App\Demo;

use App\Enums\ExpenseCategory;
use App\Enums\ReimbursementStatus;

/** Um comprovante fictício com gabarito. Usado pelo reembolso:demo e pelo eval:run. */
final readonly class DemoScenario
{
    /**
     * @param  list<string>  $lines  texto impresso no PDF
     * @param  array<string, mixed>  $expected  o que a IA deveria extrair (gabarito)
     * @param  bool  $expectedSuspicious  se a IA deveria marcar o comprovante como suspeito
     */
    public function __construct(
        public string $title,
        public string $fileName,
        public array $lines,
        public ExpenseCategory $category,
        public string $claimedAmount,
        public string $description,
        public array $expected,
        public ReimbursementStatus $expectedStatus,
        public string $why,
        public bool $expectedSuspicious = false,
    ) {}
}
