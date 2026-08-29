<?php

namespace Modules\Sync\Enums;

enum SyncRecordStatus: string
{
    case Synced = 'synced';
    case Error = 'error';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
