<?php

use App\Ai\Data\ExtractedReceipt;
use App\Enums\ExpenseCategory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/** Arquivo real (não fake) para que a detecção de MIME pelo conteúdo seja exercitada. */
function realUpload(string $contents, string $clientName): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $clientName, null, null, true);
}

function pdfUpload(string $seed = 'nota-1', string $name = 'nota.pdf'): UploadedFile
{
    return realUpload("%PDF-1.4\n% {$seed}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n", $name);
}

function receipt(array $overrides = []): ExtractedReceipt
{
    $data = array_merge([
        'cnpj' => '11222333000181',
        'issuerName' => 'Restaurante Bom Prato LTDA',
        'issueDate' => CarbonImmutable::today()->subDays(3),
        'totalCents' => 4_590,
        'category' => ExpenseCategory::Alimentacao,
        'confidence' => 0.97,
        'suspiciousContent' => false,
    ], $overrides);

    return new ExtractedReceipt(...$data);
}
