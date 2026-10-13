<?php

namespace App\Ai\Contracts;

use App\Ai\Data\ExtractionResult;
use App\Ai\Exceptions\AiProviderUnavailable;
use App\Ai\Exceptions\ExtractionFailed;

/**
 * Porta para a IA. O resto do sistema só conhece esta interface,
 * então dá para trocar de provedor ou usar um fake nos testes.
 */
interface ReceiptExtractor
{
    /**
     * @throws ExtractionFailed quando a resposta da IA é inválida (não adianta tentar de novo)
     * @throws AiProviderUnavailable quando o provedor está fora ou limitando (vale tentar de novo)
     */
    public function extract(string $contents, string $mimeType): ExtractionResult;
}
