<?php

namespace App\Domain\Reimbursement;

use App\Enums\ReimbursementStatus;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Tentativa de mudar o status de um pedido por um caminho que o ciclo de vida não permite. */
final class InvalidTransition extends RuntimeException
{
    public static function cannotReview(ReimbursementStatus $current): self
    {
        return new self("Só pedidos em revisão podem ser aprovados ou recusados (status atual: {$current->value}).");
    }

    /** Responde 409 Conflict na API (o Laravel chama este método sozinho). */
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
