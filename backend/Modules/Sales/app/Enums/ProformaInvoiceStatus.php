<?php

namespace Modules\Sales\Enums;

enum ProformaInvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Accepted = 'accepted';
    case Converted = 'converted';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Issued], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::Draft, self::Issued, self::Accepted], true);
    }

    public function isConvertible(): bool
    {
        return in_array($this, [self::Issued, self::Accepted], true);
    }
}
