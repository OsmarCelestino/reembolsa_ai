<?php

namespace App\Domain\Reimbursement;

use App\Enums\ReimbursementStatus;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class InvalidTransition extends RuntimeException
{
    public static function cannotReview(ReimbursementStatus $current): self
    {
        return new self("Só pedidos em revisão podem ser aprovados ou recusados (status atual: {$current->value}).");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
