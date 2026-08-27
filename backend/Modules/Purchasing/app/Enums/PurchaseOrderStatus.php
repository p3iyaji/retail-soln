<?php

namespace Modules\Purchasing\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Partial = 'partial';
    case Received = 'received';
    case Closed = 'closed';

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    public function canSubmit(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    public function canApproveOrReject(): bool
    {
        return $this === self::PendingApproval;
    }

    public function canReceive(): bool
    {
        return $this === self::Approved || $this === self::Partial;
    }
}
