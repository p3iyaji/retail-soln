<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalCartItem;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Pricing\Services\PricingService;
use Modules\Products\Models\ProductVariant;
use RuntimeException;

class PortalCartService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly PortalStoreResolver $stores,
        private readonly PortalOrganizationResolver $organizations,
    ) {}

    /**
     * @return array{
     *   lines: list<array<string, mixed>>,
     *   totals: array<string, string>,
     *   store: array{id: int, name: string}|null,
     *   removedLines: list<array{id: int, variantId: int|null, reason: string}>
     * }
     */
    public function getCart(CustomerAccount $account, ?int $storeId = null): array
    {
        $organization = $this->organizations->resolve();
        $this->assertAccountOrg($account, $organization);
        $store = $this->stores->resolve($organization, $storeId);

        $items = PortalCartItem::query()
            ->where('customer_account_id', $account->id)
            ->with(['variant.product.images', 'variant.images'])
            ->orderBy('id')
            ->get();

        return $this->buildCartPayload($organization, $store, $items);
    }

    /**
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>, store: array{id: int, name: string}|null}
     */
    public function addItem(CustomerAccount $account, int $variantId, string $qty, ?int $storeId = null): array
    {
        if (bccomp($qty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'qty' => ['Quantity must be greater than zero.'],
            ]);
        }

        $organization = $this->organizations->resolve();
        $this->assertAccountOrg($account, $organization);
        $variant = $this->findSellableVariant($organization->id, $variantId);

        DB::transaction(function () use ($account, $variant, $qty) {
            $line = PortalCartItem::query()
                ->where('customer_account_id', $account->id)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            if ($line !== null) {
                $line->quantity = bcadd((string) $line->quantity, $qty, 4);
                $line->save();

                return;
            }

            try {
                PortalCartItem::query()->create([
                    'customer_account_id' => $account->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $qty,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if (! $this->isUniqueCartLineViolation($e)) {
                    throw $e;
                }

                $retry = PortalCartItem::query()
                    ->where('customer_account_id', $account->id)
                    ->where('product_variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first();

                if ($retry === null) {
                    throw $e;
                }

                $retry->quantity = bcadd((string) $retry->quantity, $qty, 4);
                $retry->save();
            }
        });

        return $this->getCart($account, $storeId);
    }

    /**
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>, store: array{id: int, name: string}|null}
     */
    public function updateItem(CustomerAccount $account, int $lineId, string $qty, ?int $storeId = null): array
    {
        $organization = $this->organizations->resolve();
        $this->assertAccountOrg($account, $organization);

        $line = PortalCartItem::query()
            ->where('customer_account_id', $account->id)
            ->where('id', $lineId)
            ->first();

        if ($line === null) {
            abort(404, 'Cart line not found.');
        }

        if (bccomp($qty, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'qty' => ['Quantity cannot be negative.'],
            ]);
        }

        if (bccomp($qty, '0', 4) === 0 || bccomp($qty, '1', 4) < 0) {
            // Qty < 1 → remove (matrix: remove or 422). Prefer remove for 0; for 0 < qty < 1 use 422.
            if (bccomp($qty, '0', 4) === 0) {
                $line->delete();
            } else {
                throw ValidationException::withMessages([
                    'qty' => ['Quantity must be at least 1, or 0 to remove.'],
                ]);
            }
        } else {
            $line->quantity = $qty;
            $line->save();
        }

        return $this->getCart($account, $storeId);
    }

    /**
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>, store: array{id: int, name: string}|null}
     */
    public function removeItem(CustomerAccount $account, int $lineId, ?int $storeId = null): array
    {
        $organization = $this->organizations->resolve();
        $this->assertAccountOrg($account, $organization);

        $deleted = PortalCartItem::query()
            ->where('customer_account_id', $account->id)
            ->where('id', $lineId)
            ->delete();

        if ($deleted === 0) {
            abort(404, 'Cart line not found.');
        }

        return $this->getCart($account, $storeId);
    }

    public function clear(CustomerAccount $account): void
    {
        PortalCartItem::query()
            ->where('customer_account_id', $account->id)
            ->delete();
    }

    /**
     * @return Collection<int, PortalCartItem>
     */
    public function lockedItems(CustomerAccount $account): Collection
    {
        return PortalCartItem::query()
            ->where('customer_account_id', $account->id)
            ->with(['variant.product.images', 'variant.images'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function findSellableVariant(int $organizationId, int $variantId): ProductVariant
    {
        $variant = ProductVariant::query()
            ->with('product')
            ->where('organization_id', $organizationId)
            ->where('id', $variantId)
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->first();

        if ($variant === null) {
            abort(404, 'Variant not found.');
        }

        return $variant;
    }

    /**
     * @param  Collection<int, PortalCartItem>  $items
     * @return array{
     *   lines: list<array<string, mixed>>,
     *   totals: array<string, string>,
     *   store: array{id: int, name: string}|null,
     *   removedLines: list<array{id: int, variantId: int|null, reason: string}>
     * }
     */
    public function buildCartPayload(Organization $organization, ?Store $store, Collection $items): array
    {
        $lines = [];
        $removedLines = [];
        $subtotal = '0.0000';
        $taxTotal = '0.0000';
        $discountTotal = '0.0000';

        foreach ($items as $item) {
            $variant = $item->variant;
            if ($variant === null) {
                $removedLines[] = [
                    'id' => $item->id,
                    'variantId' => $item->product_variant_id,
                    'reason' => 'missing_variant',
                ];
                $item->delete();

                continue;
            }

            if (! $variant->is_active) {
                $removedLines[] = [
                    'id' => $item->id,
                    'variantId' => $variant->id,
                    'reason' => 'inactive_variant',
                ];
                $item->delete();

                continue;
            }

            if ($variant->product === null || ! $variant->product->is_active) {
                $removedLines[] = [
                    'id' => $item->id,
                    'variantId' => $variant->id,
                    'reason' => 'inactive_product',
                ];
                $item->delete();

                continue;
            }

            $priced = $this->priceLine($organization->id, $variant->id, $store?->id, (string) $item->quantity);
            // PricingService net/gross/tax/discount are line-level for the requested qty.
            $qty = $priced['qty'];
            $unitNet = bccomp($qty, '0', 4) > 0 ? bcdiv($priced['netPrice'], $qty, 4) : '0.0000';
            $unitGross = bccomp($qty, '0', 4) > 0 ? bcdiv($priced['grossPrice'], $qty, 4) : '0.0000';

            $lines[] = [
                'id' => $item->id,
                'variantId' => $variant->id,
                'productId' => $variant->product_id,
                'productName' => $variant->product->name,
                'variantName' => $variant->name,
                'sku' => $variant->sku,
                'imageUrl' => $this->resolveLineImageUrl($variant),
                'qty' => $qty,
                'unitListPrice' => $priced['listPrice'],
                'unitNetPrice' => $unitNet,
                'unitGrossPrice' => $unitGross,
                'lineTax' => $priced['tax']['amount'],
                'lineDiscount' => $priced['discountAmount'],
                'lineTotal' => $priced['lineTotal'],
            ];

            $subtotal = bcadd($subtotal, $priced['netPrice'], 4);
            $taxTotal = bcadd($taxTotal, $priced['tax']['amount'], 4);
            $discountTotal = bcadd($discountTotal, $priced['discountAmount'], 4);
        }

        $grand = bcadd($subtotal, $taxTotal, 4);

        return [
            'lines' => $lines,
            'totals' => [
                'subtotal' => $subtotal,
                'discountTotal' => $discountTotal,
                'taxTotal' => $taxTotal,
                'deliveryFee' => '0.0000',
                'grandTotal' => $grand,
            ],
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
            ] : null,
            'removedLines' => $removedLines,
        ];
    }

    /**
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
    public function priceLine(int $organizationId, int $variantId, ?int $storeId, string $qty): array
    {
        if ($storeId === null) {
            throw ValidationException::withMessages([
                'storeId' => ['A store is required to price cart lines.'],
            ]);
        }

        try {
            return $this->pricing->resolve($organizationId, $variantId, $storeId, $qty);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'variantId' => [$e->getMessage()],
            ]);
        }
    }

    private function resolveLineImageUrl(ProductVariant $variant): ?string
    {
        $variantImages = $variant->relationLoaded('images')
            ? $variant->images
            : $variant->images()->orderBy('sort_order')->get();

        $variantImage = $variantImages->firstWhere('is_primary', true) ?? $variantImages->first();
        if ($variantImage !== null) {
            return $variantImage->url;
        }

        $product = $variant->product;
        if ($product === null) {
            return null;
        }

        $productImages = $product->relationLoaded('images')
            ? $product->images
            : $product->images()->orderBy('sort_order')->get();

        $productImage = $productImages->firstWhere('is_primary', true) ?? $productImages->first();

        return $productImage?->url;
    }

    private function assertAccountOrg(CustomerAccount $account, Organization $organization): void
    {
        if ((int) $account->organization_id !== (int) $organization->id) {
            abort(403, 'Account does not belong to this portal organization.');
        }
    }

    private function isUniqueCartLineViolation(\Illuminate\Database\QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'portal_cart_items_customer_account_id_product_variant_id')
            || str_contains($message, 'UNIQUE constraint failed: portal_cart_items');
    }
}
