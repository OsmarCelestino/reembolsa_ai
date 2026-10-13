<?php

namespace App\Ai\Exceptions;

use LogicException;

final class ModelNotAllowed extends LogicException
{
    public static function for(string $model): self
    {
        return new self("O modelo [{$model}] não está na lista LLM_ALLOWED_MODELS.");
    }
}
