<?php

namespace Modules\Pricing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Organization\Models\Store;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceList;
use Modules\Pricing\Models\Promotion;
use Modules\Products\Models\ProductVariant;
use RuntimeException;

class PricingService
{
    public function __construct(
        private readonly PromotionService $promotions,
        private readonly TaxService $tax,
    ) {}

    /**
     * Resolve unit list price: store-scoped list → org default list → variant.price.
     * customerId is accepted for forward compat but ignored for list selection.
     *
     * @return array{
     *   unitPrice: string,
     *   listPrice: string,
     *   discountAmount: string,
     *   netPrice: string,
     *   tax: array{name: string, rate: string, inclusive: bool, amount: string},
     *   grossPrice: string,
     *   lineTotal: string,
     *   qty: string,
     *   appliedPromotions: list<array{id: int, name: string, ruleId: int, type: string, discountAmount: string}>,
     *   priceListId: int|null,
     *   variantId: int,
     *   storeId: int
     * }
     */
    public function resolve(
        int $organizationId,
        int $variantId,
        int $storeId,
        string $qty = '1',
        ?int $customerId = null,
    ): array {
        unset($customerId); // forward compat — ignored until Story 4.2 customer groups

        $variant = ProductVariant::query()
            ->where('organization_id', $organizationId)
            ->where('id', $variantId)
            ->first();

        if ($variant === null) {
            throw new RuntimeException('Variant not found.');
        }

        if (! $variant->is_active) {
            throw new RuntimeException('Variant is inactive.');
        }

        $store = Store::query()
            ->where('organization_id', $organizationId)
            ->where('id', $storeId)
            ->first();

        if ($store === null) {
            throw new RuntimeException('Store not found.');
        }

        if (bccomp($qty, '0', 4) <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }

        [$listPrice, $priceListId] = $this->resolveListPrice($organizationId, $storeId, $variant);

        $promo = $this->promotions->bestDiscount(
            $organizationId,
            $storeId,
            $variant->id,
            $listPrice,
            $qty,
        );

        $lineList = bcmul($listPrice, $qty, 8);
        $lineAfterDiscount = bcsub($lineList, $promo['discountAmount'], 8);
        if (bccomp($lineAfterDiscount, '0', 8) < 0) {
            $lineAfterDiscount = '0';
        }

        $unitAfterDiscount = bcdiv($lineAfterDiscount, $qty, 8);

        $taxResult = $this->tax->apply($store, $lineAfterDiscount);

        return [
            'unitPrice' => $this->money($unitAfterDiscount),
            'listPrice' => $this->money($listPrice),
            'discountAmount' => $promo['discountAmount'],
            'netPrice' => $taxResult['net'],
            'tax' => [
                'name' => $taxResult['name'],
                'rate' => $taxResult['rate'],
                'inclusive' => $taxResult['inclusive'],
                'amount' => $taxResult['amount'],
            ],
            'grossPrice' => $taxResult['gross'],
            'lineTotal' => $taxResult['gross'],
            'qty' => $this->money($qty),
            'appliedPromotions' => $promo['appliedPromotions'],
            'priceListId' => $priceListId,
            'variantId' => $variant->id,
            'storeId' => $store->id,
        ];
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function resolveListPrice(int $organizationId, int $storeId, ProductVariant $variant): array
    {
        $storePrice = $this->findPriceOnActiveList($organizationId, $storeId, $variant->id, storeScoped: true);
        if ($storePrice !== null) {
            return [$this->money((string) $storePrice->amount), $storePrice->price_list_id];
        }

        $orgPrice = $this->findPriceOnActiveList($organizationId, $storeId, $variant->id, storeScoped: false);
        if ($orgPrice !== null) {
            return [$this->money((string) $orgPrice->amount), $orgPrice->price_list_id];
        }

        return [$this->money((string) $variant->price), null];
    }

    private function findPriceOnActiveList(
        int $organizationId,
        int $storeId,
        int $variantId,
        bool $storeScoped,
    ): ?Price {
        // Prefer default list, then lowest list id — never let lowest prices.id override list rank.
        $listQuery = PriceList::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereExists(function ($q) use ($organizationId, $variantId): void {
                $q->selectRaw('1')
                    ->from('prices')
                    ->whereColumn('prices.price_list_id', 'price_lists.id')
                    ->where('prices.organization_id', $organizationId)
                    ->where('prices.product_variant_id', $variantId);
            })
            ->orderByDesc('is_default')
            ->orderBy('id');

        if ($storeScoped) {
            $listQuery->where('store_id', $storeId);
        } else {
            $listQuery->whereNull('store_id');
        }

        $winningList = $listQuery->first();
        if ($winningList === null) {
            return null;
        }

        return Price::query()
            ->where('organization_id', $organizationId)
            ->where('price_list_id', $winningList->id)
            ->where('product_variant_id', $variantId)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array{name: string, store_id?: int|null, is_active?: bool, is_default?: bool}  $attributes
     */
    public function createPriceList(int $organizationId, array $attributes): PriceList
    {
        $isDefault = (bool) ($attributes['is_default'] ?? false);

        return DB::transaction(function () use ($organizationId, $attributes, $isDefault) {
            if ($isDefault) {
                PriceList::query()
                    ->where('organization_id', $organizationId)
                    ->whereNull('store_id')
                    ->update(['is_default' => false]);
            }

            return PriceList::query()->create([
                'organization_id' => $organizationId,
                'store_id' => $attributes['store_id'] ?? null,
                'name' => $attributes['name'],
                'is_active' => $attributes['is_active'] ?? true,
                'is_default' => $isDefault && ($attributes['store_id'] ?? null) === null,
            ]);
        });
    }

    /**
     * @param  array{name?: string, store_id?: int|null, is_active?: bool, is_default?: bool}  $attributes
     */
    public function updatePriceList(PriceList $list, int $organizationId, array $attributes): PriceList
    {
        return DB::transaction(function () use ($list, $organizationId, $attributes) {
            if (array_key_exists('is_default', $attributes) && $attributes['is_default']) {
                PriceList::query()
                    ->where('organization_id', $organizationId)
                    ->whereNull('store_id')
                    ->where('id', '!=', $list->id)
                    ->update(['is_default' => false]);
            }

            $storeId = array_key_exists('store_id', $attributes) ? $attributes['store_id'] : $list->store_id;

            // Store-scoped lists cannot be org default.
            if ($storeId !== null) {
                $isDefault = false;
            } elseif (array_key_exists('is_default', $attributes)) {
                $isDefault = (bool) $attributes['is_default'];
            } else {
                $isDefault = $list->is_default;
            }

            $list->fill([
                'name' => $attributes['name'] ?? $list->name,
                'store_id' => $storeId,
                'is_active' => $attributes['is_active'] ?? $list->is_active,
                'is_default' => $isDefault,
            ]);
            $list->save();

            return $list->refresh();
        });
    }

    /**
     * @param  array{price_list_id: int, product_variant_id: int, amount: string}  $attributes
     */
    public function upsertPrice(int $organizationId, array $attributes): Price
    {
        $list = PriceList::query()
            ->where('organization_id', $organizationId)
            ->where('id', $attributes['price_list_id'])
            ->firstOrFail();

        $variant = ProductVariant::query()
            ->where('organization_id', $organizationId)
            ->where('id', $attributes['product_variant_id'])
            ->firstOrFail();

        $price = Price::query()->updateOrCreate(
            [
                'price_list_id' => $list->id,
                'product_variant_id' => $variant->id,
            ],
            [
                'organization_id' => $organizationId,
                'amount' => $attributes['amount'],
            ],
        );

        return $price->fresh();
    }

    /**
     * @param  array{
     *   name: string,
     *   store_id?: int|null,
     *   starts_at?: string|null,
     *   ends_at?: string|null,
     *   is_active?: bool,
     *   rules: list<array<string, mixed>>
     * }  $attributes
     */
    public function createPromotion(int $organizationId, array $attributes): Promotion
    {
        return DB::transaction(function () use ($organizationId, $attributes) {
            $promotion = Promotion::query()->create([
                'organization_id' => $organizationId,
                'store_id' => $attributes['store_id'] ?? null,
                'name' => $attributes['name'],
                'starts_at' => $attributes['starts_at'] ?? null,
                'ends_at' => $attributes['ends_at'] ?? null,
                'is_active' => $attributes['is_active'] ?? true,
            ]);

            foreach ($attributes['rules'] as $rule) {
                $promotion->rules()->create($this->ruleAttributes($rule));
            }

            return $promotion->load('rules');
        });
    }

    /**
     * @param  array{
     *   name?: string,
     *   store_id?: int|null,
     *   starts_at?: string|null,
     *   ends_at?: string|null,
     *   is_active?: bool,
     *   rules?: list<array<string, mixed>>
     * }  $attributes
     */
    public function updatePromotion(Promotion $promotion, array $attributes): Promotion
    {
        return DB::transaction(function () use ($promotion, $attributes) {
            $promotion->fill([
                'name' => $attributes['name'] ?? $promotion->name,
                'store_id' => array_key_exists('store_id', $attributes) ? $attributes['store_id'] : $promotion->store_id,
                'starts_at' => array_key_exists('starts_at', $attributes) ? $attributes['starts_at'] : $promotion->starts_at,
                'ends_at' => array_key_exists('ends_at', $attributes) ? $attributes['ends_at'] : $promotion->ends_at,
                'is_active' => $attributes['is_active'] ?? $promotion->is_active,
            ]);
            $promotion->save();

            if (array_key_exists('rules', $attributes)) {
                $promotion->rules()->delete();
                foreach ($attributes['rules'] as $rule) {
                    $promotion->rules()->create($this->ruleAttributes($rule));
                }
            }

            return $promotion->load('rules');
        });
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private function ruleAttributes(array $rule): array
    {
        return [
            'type' => $rule['type'],
            'value' => $rule['value'] ?? '0',
            'product_variant_id' => $rule['product_variant_id'] ?? null,
            'buy_qty' => $rule['buy_qty'] ?? null,
            'get_qty' => $rule['get_qty'] ?? null,
            'get_discount_percent' => $rule['get_discount_percent'] ?? null,
        ];
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 4);
    }
}
