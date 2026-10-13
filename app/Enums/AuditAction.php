<?php

namespace App\Enums;

enum AuditAction: string
{
    case StatusChanged = 'status_changed';
    case ReceiptDownloaded = 'receipt_downloaded';
    case ReceiptPurged = 'receipt_purged';
}
