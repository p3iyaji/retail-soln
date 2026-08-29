<?php

namespace Modules\Sync\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Modules\Customers\Models\Customer;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Organization\Models\Store;
use Modules\Pricing\Models\Price;
use Modules\Products\Models\Product;
use Modules\Sync\Models\Device;
use Modules\Sync\Support\PullCursor;

class SyncPullService
{
    /**
     * @return array{
     *   data: array{
     *     products: list<array<string, mixed>>,
     *     prices: list<array<string, mixed>>,
     *     taxes: list<array<string, mixed>>,
     *     customers: list<array<string, mixed>>,
     *     inventory: list<array<string, mixed>>,
     *     config: list<array<string, mixed>>,
     *     deleted: array{
     *       products: list<int>,
     *       prices: list<array{variantId: int}>,
     *       customers: list<int>,
     *       inventory: list<array{variantId: int}>,
     *     },
     *   },
     *   meta: array{cursor: string, hasMore: bool},
     * }
     */
    public function pull(
        int $organizationId,
        int $storeId,
        string $deviceUuid,
        ?string $cursorToken,
        ?int $limit = null,
    ): array {
        $device = $this->resolveDevice($organizationId, $storeId, $deviceUuid);

        if (! $device->enabled) {
            throw new DeviceDisabledException('Device is disabled and cannot sync.');
        }

        $device->forceFill(['last_seen_at' => now(), 'store_id' => $storeId])->save();

        $store = Store::query()
            ->where('organization_id', $organizationId)
            ->whereKey($storeId)
            ->first();

        if ($store === null) {
            throw new InvalidPullCursorException('Store not found.');
        }

        $limit = max(1, min($limit ?? (int) config('sync.pull_limit', 100), 100));
        $cursor = PullCursor::decode($cursorToken, $storeId);

        $productsPage = $this->pullProducts($organizationId, $cursor->watermark('products'), $limit);
        $pricesPage = $this->pullPrices($organizationId, $storeId, $cursor->watermark('prices'), $limit);
        $customersPage = $this->pullCustomers($organizationId, $cursor->watermark('customers'), $limit);
        $inventoryPage = $this->pullInventory($organizationId, $storeId, $cursor->watermark('inventory'), $limit);
        $taxPage = $this->pullTax($store, $cursor->watermark('taxes'));
        $configPage = $this->pullConfig($store, $cursor->watermark('config'));

        $hasMore = $productsPage['hasMore']
            || $pricesPage['hasMore']
            || $customersPage['hasMore']
            || $inventoryPage['hasMore']
            || $taxPage['hasMore']
            || $configPage['hasMore'];

        $next = $cursor
            ->withWatermark('products', $productsPage['watermark'])
            ->withWatermark('prices', $pricesPage['watermark'])
            ->withWatermark('taxes', $taxPage['watermark'])
            ->withWatermark('customers', $customersPage['watermark'])
            ->withWatermark('inventory', $inventoryPage['watermark'])
            ->withWatermark('config', $configPage['watermark']);

        return [
            'data' => [
                'products' => $productsPage['rows'],
                'prices' => $pricesPage['rows'],
                'taxes' => $taxPage['rows'],
                'customers' => $customersPage['rows'],
                'inventory' => $inventoryPage['rows'],
                'config' => $configPage['rows'],
                'deleted' => [
                    'products' => $productsPage['deleted'],
                    'prices' => $pricesPage['deleted'],
                    'customers' => $customersPage['deleted'],
                    'inventory' => $inventoryPage['deleted'],
                ],
            ],
            'meta' => [
                'cursor' => $next->encode(),
                'hasMore' => $hasMore,
            ],
        ];
    }

    /**
     * @param  array{at: string, id: int}|null  $mark
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   deleted: list<int>,
     *   watermark: array{at: string, id: int}|null,
     *   hasMore: bool,
     * }
     */
    private function pullProducts(int $organizationId, ?array $mark, int $limit): array
    {
        $query = Product::query()
            ->where('organization_id', $organizationId)
            ->with(['variants.barcodes']);

        $this->applyWatermark($query, $mark);
        $products = $query->orderBy('updated_at')->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $products->count() > $limit;
        $products = $products->take($limit);

        $rows = [];
        $deleted = [];
        $watermark = $mark;

        foreach ($products as $product) {
            $watermark = $this->markFromModel($product);
            if (! $product->is_active) {
                $deleted[] = (int) $product->id;
                $rows[] = [
                    'id' => (int) $product->id,
                    'isActive' => false,
                    'updatedAt' => $product->updated_at?->toIso8601String(),
                ];
                continue;
            }

            $variants = [];
            foreach ($product->variants as $variant) {
                $primary = $variant->relationLoaded('barcodes')
                    ? ($variant->barcodes->firstWhere('is_primary', true) ?? $variant->barcodes->first())
                    : null;

                $variants[] = [
                    'id' => (int) $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'barcode' => $primary?->code ?? $product->barcode,
                    'isActive' => (bool) $variant->is_active,
                    'isDefault' => (bool) $variant->is_default,
                    'price' => bcadd((string) $variant->price, '0', 4),
                ];
            }

            $rows[] = [
                'id' => (int) $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'categoryId' => $product->category_id,
                'isActive' => true,
                'sellingPrice' => $product->selling_price !== null
                    ? bcadd((string) $product->selling_price, '0', 4)
                    : null,
                'variants' => $variants,
                'updatedAt' => $product->updated_at?->toIso8601String(),
            ];
        }

        return [
            'rows' => $rows,
            'deleted' => $deleted,
            'watermark' => $watermark,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * Store price-list rows; client falls back to variant list price when absent.
     *
     * @param  array{at: string, id: int}|null  $mark
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   deleted: list<array{variantId: int}>,
     *   watermark: array{at: string, id: int}|null,
     *   hasMore: bool,
     * }
     */
    private function pullPrices(int $organizationId, int $storeId, ?array $mark, int $limit): array
    {
        $query = Price::query()
            ->where('prices.organization_id', $organizationId)
            ->whereHas('priceList', function (Builder $q) use ($organizationId, $storeId): void {
                $q->where('organization_id', $organizationId)
                    ->where('store_id', $storeId)
                    ->where('is_active', true);
            });

        $this->applyWatermark($query, $mark, 'prices');
        $prices = $query->orderBy('prices.updated_at')->orderBy('prices.id')->limit($limit + 1)->get();
        $hasMore = $prices->count() > $limit;
        $prices = $prices->take($limit);

        $rows = [];
        $watermark = $mark;

        foreach ($prices as $price) {
            $watermark = $this->markFromModel($price);
            $amount = bcadd((string) $price->amount, '0', 4);
            $rows[] = [
                'id' => (int) $price->id,
                'variantId' => (int) $price->product_variant_id,
                'storeId' => $storeId,
                'unitPrice' => $amount,
                'listPrice' => $amount,
                'updatedAt' => $price->updated_at?->toIso8601String(),
            ];
        }

        return [
            'rows' => $rows,
            'deleted' => [],
            'watermark' => $watermark,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * @param  array{at: string, id: int}|null  $mark
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   deleted: list<int>,
     *   watermark: array{at: string, id: int}|null,
     *   hasMore: bool,
     * }
     */
    private function pullCustomers(int $organizationId, ?array $mark, int $limit): array
    {
        $query = Customer::withTrashed()
            ->where('organization_id', $organizationId);

        $this->applyWatermark($query, $mark);
        $customers = $query->orderBy('updated_at')->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $customers->count() > $limit;
        $customers = $customers->take($limit);

        $rows = [];
        $deleted = [];
        $watermark = $mark;

        foreach ($customers as $customer) {
            $watermark = $this->markFromModel($customer);
            $trashed = $customer->trashed() || ! $customer->is_active;
            if ($trashed) {
                $deleted[] = (int) $customer->id;
                continue;
            }

            $rows[] = [
                'id' => (int) $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'isActive' => true,
                'updatedAt' => $customer->updated_at?->toIso8601String(),
            ];
        }

        return [
            'rows' => $rows,
            'deleted' => $deleted,
            'watermark' => $watermark,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * @param  array{at: string, id: int}|null  $mark
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   deleted: list<array{variantId: int}>,
     *   watermark: array{at: string, id: int}|null,
     *   hasMore: bool,
     * }
     */
    private function pullInventory(int $organizationId, int $storeId, ?array $mark, int $limit): array
    {
        $query = InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->where('location_type', LocationType::Store->value)
            ->where('location_id', $storeId);

        $this->applyWatermark($query, $mark);
        $balances = $query->orderBy('updated_at')->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $balances->count() > $limit;
        $balances = $balances->take($limit);

        $rows = [];
        $watermark = $mark;

        foreach ($balances as $balance) {
            $watermark = $this->markFromModel($balance);
            $rows[] = [
                'variantId' => (int) $balance->product_variant_id,
                'storeId' => $storeId,
                'available' => bcadd((string) $balance->available, '0', 4),
                'updatedAt' => $balance->updated_at?->toIso8601String(),
            ];
        }

        return [
            'rows' => $rows,
            'deleted' => [],
            'watermark' => $watermark,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * @param  array{at: string, id: int}|null  $mark
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   watermark: array{at: string, id: int}|null,
     *   hasMore: bool,
     * }
     */
    private function pullTax(Store $store, ?array $mark): array
    {
        if (! $this->storeChangedSince($store, $mark)) {
            return [
                'rows' => [],
                'watermark' => $mark,
                'hasMore' => false,
            ];
        }

        $taxConfig = is_array($store->tax_config) ? $store->tax_config : [];

        return [
            'rows' => [[
                'storeId' => (int) $store->id,
                'name' => isset($taxConfig['name']) && is_string($taxConfig['name']) && trim($taxConfig['name']) !== ''
                    ? trim($taxConfig['name'])
                    : 'Tax',
                'rate' => bcadd((string) ($taxConfig['rate'] ?? '0'), '0', 4),
                'inclusive' => (bool) ($taxConfig['inclusive'] ?? false),
                'updatedAt' => $store->updated_at?->toIso8601String(),
            ]],
            'watermark' => $this->markFromModel($store),
            'hasMore' => false,
        ];
    }

    /**
     * @param  array{at: string, id: int}|null  $mark
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   watermark: array{at: string, id: int}|null,
     *   hasMore: bool,
     * }
     */
    private function pullConfig(Store $store, ?array $mark): array
    {
        if (! $this->storeChangedSince($store, $mark)) {
            return [
                'rows' => [],
                'watermark' => $mark,
                'hasMore' => false,
            ];
        }

        return [
            'rows' => [[
                'storeId' => (int) $store->id,
                'storeName' => $store->name,
                'currency' => $store->currency ?: 'USD',
                'updatedAt' => $store->updated_at?->toIso8601String(),
            ]],
            'watermark' => $this->markFromModel($store),
            'hasMore' => false,
        ];
    }

    /**
     * @param  array{at: string, id: int}|null  $mark
     */
    private function storeChangedSince(Store $store, ?array $mark): bool
    {
        if ($mark === null) {
            return true;
        }

        $updatedAt = $store->updated_at;
        if ($updatedAt === null) {
            return true;
        }

        $markAt = Carbon::parse($mark['at']);
        if ($updatedAt->gt($markAt)) {
            return true;
        }

        return $updatedAt->eq($markAt) && (int) $store->id > $mark['id'];
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array{at: string, id: int}|null  $mark
     */
    private function applyWatermark(Builder $query, ?array $mark, ?string $table = null): void
    {
        if ($mark === null) {
            return;
        }

        $atCol = $table ? "{$table}.updated_at" : 'updated_at';
        $idCol = $table ? "{$table}.id" : 'id';
        $at = Carbon::parse($mark['at'])->toDateTimeString();
        $id = $mark['id'];

        $query->where(function (Builder $q) use ($atCol, $idCol, $at, $id): void {
            $q->where($atCol, '>', $at)
                ->orWhere(function (Builder $inner) use ($atCol, $idCol, $at, $id): void {
                    $inner->where($atCol, '=', $at)->where($idCol, '>', $id);
                });
        });
    }

    /**
     * @return array{at: string, id: int}
     */
    private function markFromModel(object $model): array
    {
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $updatedAt = $model->updated_at;

        return [
            'at' => $updatedAt instanceof Carbon
                ? $updatedAt->toIso8601String()
                : Carbon::parse((string) $updatedAt)->toIso8601String(),
            'id' => (int) $model->getKey(),
        ];
    }

    private function resolveDevice(int $organizationId, int $storeId, string $deviceUuid): Device
    {
        try {
            return Device::query()->firstOrCreate(
                [
                    'organization_id' => $organizationId,
                    'uuid' => $deviceUuid,
                ],
                [
                    'store_id' => $storeId,
                    'enabled' => true,
                    'last_seen_at' => now(),
                ],
            );
        } catch (QueryException $e) {
            if (! $this->isUniqueDeviceViolation($e)) {
                throw $e;
            }

            $device = Device::query()
                ->where('organization_id', $organizationId)
                ->where('uuid', $deviceUuid)
                ->first();

            if ($device === null) {
                throw $e;
            }

            return $device;
        }
    }

    private function isUniqueDeviceViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'devices_organization_id_uuid')
            || (str_contains($message, 'unique') && str_contains($message, 'uuid'));
    }
}
