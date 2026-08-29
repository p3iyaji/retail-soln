<?php

namespace Modules\Sales\Enums;

enum ProformaConvertTarget: string
{
    case Sale = 'sale';
    case Invoice = 'invoice';
    case Both = 'both';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function createsSale(): bool
    {
        return in_array($this, [self::Sale, self::Both], true);
    }
}
