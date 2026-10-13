<?php

namespace App\Enums;

enum ReimbursementStatus: string
{
    case Submitted = 'submitted';
    case Processing = 'processing';
    case AutoApproved = 'auto_approved';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return in_array($this, [self::AutoApproved, self::Approved, self::Rejected], true);
    }
}
