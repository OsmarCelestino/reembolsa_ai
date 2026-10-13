<?php

namespace App\Policies;

use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Quem vê e quem revisa um pedido. */
class ReimbursementPolicy
{
    public function view(User $user, Reimbursement $reimbursement): Response
    {
        // 404 em vez de 403: não revela que o pedido de outra pessoa existe.
        return $user->isReviewer() || $reimbursement->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function review(User $user, Reimbursement $reimbursement): Response
    {
        if (! $user->isReviewer()) {
            return Response::deny('Apenas analistas podem revisar pedidos.');
        }

        // Segregação de funções: ninguém aprova o próprio reembolso.
        return $reimbursement->user_id === $user->id
            ? Response::deny('Você não pode revisar o seu próprio pedido.')
            : Response::allow();
    }

    /** Reenviar para a IA um pedido que falhou (operação de suporte). */
    public function reprocess(User $user, Reimbursement $reimbursement): Response
    {
        return $user->isReviewer()
            ? Response::allow()
            : Response::deny('Apenas analistas podem reprocessar pedidos.');
    }
}
