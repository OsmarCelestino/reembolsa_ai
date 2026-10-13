<?php

namespace App\Enums;

/**
 * Ciclo de vida do pedido:
 * submitted → processing → auto_approved | pending_review | rejected;
 * pending_review → approved | rejected (analista); failed se a fila esgotar as tentativas.
 */
enum ReimbursementStatus: string
{
    case Submitted = 'submitted';
    case Processing = 'processing';
    case AutoApproved = 'auto_approved';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
