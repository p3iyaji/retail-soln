<?php

namespace Modules\Pricing\Services;

use Illuminate\Support\Carbon;
use Modules\Pricing\Enums\PromotionRuleType;
use Modules\Pricing\Models\Promotion;
use Modules\Pricing\Models\PromotionRule;

/**
 * Applies percent / fixed / simple BOGO. MVP: best single promo (highest savings).
 */
class PromotionService
{
    /**
     * @return array{
     *   discountAmount: string,
     *   appliedPromotions: list<array{id: int, name: string, ruleId: int, type: string, discountAmount: string}>
     * }
     */
    public function bestDiscount(
        int $organizationId,
        int $storeId,
        int $productVariantId,
        string $unitListPrice,
        string $qty,
        ?Carbon $at = null,
    ): array {
        $at ??= now();
        $zero = [
            'discountAmount' => '0.0000',
            'appliedPromotions' => [],
        ];

        if (bccomp($qty, '0', 4) <= 0 || bccomp($unitListPrice, '0', 4) <= 0) {
            return $zero;
        }

        $promotions = Promotion::query()
            ->with('rules')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where(function ($q) use ($storeId): void {
                $q->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->where(function ($q) use ($at): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at);
            })
            ->where(function ($q) use ($at): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at);
            })
            ->get();

        $bestDiscount = '0';
        $bestApplied = null;

        foreach ($promotions as $promotion) {
            foreach ($promotion->rules as $rule) {
                if ($rule->product_variant_id !== null
                    && (int) $rule->product_variant_id !== $productVariantId) {
                    continue;
                }

                $discount = $this->discountForRule($rule, $unitListPrice, $qty);
                if (bccomp($discount, $bestDiscount, 8) > 0) {
                    $bestDiscount = $discount;
                    $bestApplied = [
                        'id' => $promotion->id,
                        'name' => $promotion->name,
                        'ruleId' => $rule->id,
                        'type' => $rule->type->value,
                        'discountAmount' => $this->money($discount),
                    ];
                }
            }
        }

        if ($bestApplied === null) {
            return $zero;
        }

        $lineTotal = bcmul($unitListPrice, $qty, 8);
        if (bccomp($bestDiscount, $lineTotal, 8) > 0) {
            $bestDiscount = $lineTotal;
            $bestApplied['discountAmount'] = $this->money($bestDiscount);
        }

        return [
            'discountAmount' => $this->money($bestDiscount),
            'appliedPromotions' => [$bestApplied],
        ];
    }

    private function discountForRule(PromotionRule $rule, string $unitListPrice, string $qty): string
    {
        return match ($rule->type) {
            PromotionRuleType::Percent => $this->percentDiscount($rule, $unitListPrice, $qty),
            PromotionRuleType::Fixed => $this->fixedDiscount($rule, $unitListPrice, $qty),
            PromotionRuleType::Bogo => $this->bogoDiscount($rule, $unitListPrice, $qty),
        };
    }

    private function percentDiscount(PromotionRule $rule, string $unitListPrice, string $qty): string
    {
        $rate = bcdiv((string) $rule->value, '100', 8);
        if (bccomp($rate, '0', 8) <= 0) {
            return '0';
        }
        if (bccomp($rate, '1', 8) > 0) {
            $rate = '1';
        }

        $line = bcmul($unitListPrice, $qty, 8);

        return bcmul($line, $rate, 8);
    }

    private function fixedDiscount(PromotionRule $rule, string $unitListPrice, string $qty): string
    {
        $perUnit = (string) $rule->value;
        if (bccomp($perUnit, '0', 8) <= 0) {
            return '0';
        }
        // Cap so unit ≥ 0
        if (bccomp($perUnit, $unitListPrice, 8) > 0) {
            $perUnit = $unitListPrice;
        }

        return bcmul($perUnit, $qty, 8);
    }

    private function bogoDiscount(PromotionRule $rule, string $unitListPrice, string $qty): string
    {
        $buyQty = (string) ($rule->buy_qty ?? '0');
        $getQty = (string) ($rule->get_qty ?? '0');
        $getPct = (string) ($rule->get_discount_percent ?? '0');

        if (bccomp($buyQty, '0', 4) <= 0 || bccomp($getQty, '0', 4) <= 0) {
            return '0';
        }

        $setSize = bcadd($buyQty, $getQty, 4);
        $sets = $this->floorDiv($qty, $setSize);
        if (bccomp($sets, '0', 0) <= 0) {
            return '0';
        }

        $discountedUnits = bcmul($sets, $getQty, 4);
        $pct = bcdiv($getPct, '100', 8);
        if (bccomp($pct, '0', 8) <= 0) {
            return '0';
        }
        if (bccomp($pct, '1', 8) > 0) {
            $pct = '1';
        }

        $perUnitOff = bcmul($unitListPrice, $pct, 8);

        return bcmul($perUnitOff, $discountedUnits, 8);
    }

    /**
     * Floor division for DECIMAL qty strings (scale 4).
     */
    private function floorDiv(string $numerator, string $denominator): string
    {
        if (bccomp($denominator, '0', 8) <= 0) {
            return '0';
        }

        $ratio = bcdiv($numerator, $denominator, 8);
        $intPart = explode('.', $ratio)[0];

        return $intPart === '' ? '0' : $intPart;
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 4);
    }
}
