<?php

namespace App\Support;

/**
 * Currency amounts: business logic uses 2dp (half-up); storage/API keeps 4dp scale.
 */
final class Money
{
    public const CURRENCY_SCALE = 2;

    public const STORAGE_SCALE = 4;

    public static function roundCurrency(string $value): string
    {
        $normalized = bcadd($value, '0', self::STORAGE_SCALE + 2);
        $negative = bccomp($normalized, '0', self::STORAGE_SCALE + 2) < 0;
        $abs = ltrim($normalized, '-');
        $rounded = self::roundHalfUpPositive($abs, self::CURRENCY_SCALE);

        return bcadd($negative ? '-'.$rounded : $rounded, '0', self::CURRENCY_SCALE);
    }

    public static function toStorage(string $value): string
    {
        return bcadd(self::roundCurrency($value), '0', self::STORAGE_SCALE);
    }

    public static function equalsAtCurrency(string $a, string $b): bool
    {
        return bccomp(self::roundCurrency($a), self::roundCurrency($b), self::CURRENCY_SCALE) === 0;
    }

    private static function roundHalfUpPositive(string $value, int $scale): string
    {
        $factor = bcpow('10', (string) $scale, 0);
        $shifted = bcmul($value, $factor, $scale + 1);
        $lastDigit = (int) substr(bcadd($shifted, '0', $scale + 1), -1);
        $trunc = bcadd($shifted, '0', 0);
        if ($lastDigit >= 5) {
            $trunc = bcadd($trunc, '1', 0);
        }

        return bcdiv($trunc, $factor, $scale);
    }
}
