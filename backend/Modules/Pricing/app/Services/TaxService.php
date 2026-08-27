<?php

namespace Modules\Pricing\Services;

use Modules\Organization\Models\Store;

/**
 * Tax from store tax_config only (rate / inclusive / name). Missing config → rate 0.
 */
class TaxService
{
    /**
     * @return array{name: string, rate: string, inclusive: bool, amount: string, net: string, gross: string}
     */
    public function apply(Store $store, string $priceAfterDiscount): array
    {
        $config = is_array($store->tax_config) ? $store->tax_config : [];
        $rate = $this->normalizeRate($config['rate'] ?? '0');
        $inclusive = (bool) ($config['inclusive'] ?? false);
        $name = is_string($config['name'] ?? null) && $config['name'] !== ''
            ? $config['name']
            : 'Tax';

        if (bccomp($rate, '0', 4) === 0) {
            return [
                'name' => $name,
                'rate' => '0.0000',
                'inclusive' => $inclusive,
                'amount' => '0.0000',
                'net' => $this->money($priceAfterDiscount),
                'gross' => $this->money($priceAfterDiscount),
            ];
        }

        if ($inclusive) {
            // tax = gross - gross/(1+rate); round net first so net + tax = gross
            $divisor = bcadd('1', $rate, 8);
            if (bccomp($divisor, '0', 8) <= 0) {
                // Guard rate ≈ -1 (and other non-positive divisors) after clamp should not reach here,
                // but keep safe fallback.
                return [
                    'name' => $name,
                    'rate' => '0.0000',
                    'inclusive' => true,
                    'amount' => '0.0000',
                    'net' => $this->money($priceAfterDiscount),
                    'gross' => $this->money($priceAfterDiscount),
                ];
            }

            $net = bcdiv($priceAfterDiscount, $divisor, 4);
            $amount = bcsub($priceAfterDiscount, $net, 4);

            return [
                'name' => $name,
                'rate' => $this->money($rate),
                'inclusive' => true,
                'amount' => $this->money($amount),
                'net' => $this->money($net),
                'gross' => $this->money($priceAfterDiscount),
            ];
        }

        // exclusive: tax = net * rate
        $amount = bcmul($priceAfterDiscount, $rate, 8);
        $gross = bcadd($priceAfterDiscount, $amount, 8);

        return [
            'name' => $name,
            'rate' => $this->money($rate),
            'inclusive' => false,
            'amount' => $this->money($amount),
            'net' => $this->money($priceAfterDiscount),
            'gross' => $this->money($gross),
        ];
    }

    /**
     * @return array{name: string, rate: string, inclusive: bool}
     */
    public function forStore(Store $store): array
    {
        $config = is_array($store->tax_config) ? $store->tax_config : [];

        return [
            'name' => is_string($config['name'] ?? null) && $config['name'] !== ''
                ? $config['name']
                : 'Tax',
            'rate' => $this->money($this->normalizeRate($config['rate'] ?? '0')),
            'inclusive' => (bool) ($config['inclusive'] ?? false),
        ];
    }

    private function normalizeRate(mixed $rate): string
    {
        if ($rate === null || $rate === '') {
            return '0';
        }

        if (! is_numeric($rate)) {
            return '0';
        }

        $normalized = (string) $rate;
        // Clamp negatives (avoids inclusive 1+rate ≤ 0 / division by zero).
        if (bccomp($normalized, '0', 8) < 0) {
            return '0';
        }

        return $normalized;
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 4);
    }
}
