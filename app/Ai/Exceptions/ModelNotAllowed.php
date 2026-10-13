<?php

namespace App\Ai\Exceptions;

use LogicException;

/** O modelo configurado não está em LLM_ALLOWED_MODELS. Erro de configuração: falha ao montar o extrator. */
final class ModelNotAllowed extends LogicException
{
    public static function for(string $model): self
    {
        return new self("O modelo [{$model}] não está na lista LLM_ALLOWED_MODELS.");
    }
}
