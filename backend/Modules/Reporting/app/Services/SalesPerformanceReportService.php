<?php

namespace Modules\Reporting\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Products\Models\ProductVariant;
use Modules\Reporting\Data\SalesReportFilters;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleItem;

/**
 * Read-only sales performance aggregation over completed POS sales for one store.
 * Reporting is not a book of record (AD-12) and never mutates domain state.
 */
class SalesPerformanceReportService
{
    private const SCALE = 4;

    public const TOP_PRODUCTS_LIMIT = 10;

    public const CSV_ROW_LIMIT = 10000;

    /**
     * @return array<string, mixed>
     */
    public function summary(Organization $organization, Store $store, SalesReportFilters $filters): array
    {
        $total = '0';
        $count = 0;
        /** @var array<int, array{salesCount: int, salesTotal: string}> $byCashier */
        $byCashier = [];

        $this->baseQuery($organization, $store, $filters)
            ->select(['id', 'total', 'cashier_id'])
            ->lazyById(500)
            ->each(function (Sale $sale) use (&$total, &$count, &$byCashier): void {
                $amount = $this->money($sale->total);
                $count++;
                $total = bcadd($total, $amount, self::SCALE);

                if ($sale->cashier_id === null) {
                    return;
                }

                $cashierId = (int) $sale->cashier_id;
                $bucket = $byCashier[$cashierId] ?? ['salesCount' => 0, 'salesTotal' => '0'];
                $bucket['salesCount']++;
                $bucket['salesTotal'] = bcadd($bucket['salesTotal'], $amount, self::SCALE);
                $byCashier[$cashierId] = $bucket;
            });

        return [
            'salesTotal' => bcadd($total, '0', self::SCALE),
            'salesCount' => $count,
            'averageOrderValue' => $count > 0
                ? bcdiv($total, (string) $count, self::SCALE)
                : bcadd('0', '0', self::SCALE),
            'cashiers' => $this->cashierRows($byCashier),
        ];
    }

    /**
     * Top sellers are scoped by the same variant/category filters as the sales themselves,
     * so a filtered report never lists products the caller excluded.
     *
     * @return list<array<string, mixed>>
     */
    public function topProducts(
        Organization $organization,
        Store $store,
        SalesReportFilters $filters,
        int $limit = self::TOP_PRODUCTS_LIMIT,
    ): array {
        /** @var array<int, array{qty: string, lineTotal: string}> $byVariant */
        $byVariant = [];

        $this->itemQuery($organization, $store, $filters)
            ->select(['id', 'product_variant_id', 'qty', 'line_total'])
            ->lazyById(500)
            ->each(function (SaleItem $item) use (&$byVariant): void {
                $variantId = (int) $item->product_variant_id;
                $bucket = $byVariant[$variantId] ?? ['qty' => '0', 'lineTotal' => '0'];
                $bucket['qty'] = bcadd($bucket['qty'], $this->money($item->qty), self::SCALE);
                $bucket['lineTotal'] = bcadd($bucket['lineTotal'], $this->money($item->line_total), self::SCALE);
                $byVariant[$variantId] = $bucket;
            });

        uksort($byVariant, function (int $a, int $b) use ($byVariant): int {
            $compare = bccomp($byVariant[$b]['lineTotal'], $byVariant[$a]['lineTotal'], self::SCALE);

            return $compare !== 0 ? $compare : $a <=> $b;
        });

        $top = array_slice($byVariant, 0, max(1, $limit), preserve_keys: true);
        $variants = ProductVariant::query()
            ->with('product:id,name')
            ->whereIn('id', array_keys($top))
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($top as $variantId => $totals) {
            /** @var ProductVariant|null $variant */
            $variant = $variants->get($variantId);
            $rows[] = [
                'productVariantId' => $variantId,
                'sku' => $variant?->sku,
                'name' => trim(($variant?->product?->name ?? 'Unknown product').' — '.($variant?->name ?? '')),
                'qty' => bcadd($totals['qty'], '0', self::SCALE),
                'lineTotal' => bcadd($totals['lineTotal'], '0', self::SCALE),
            ];
        }

        return $rows;
    }

    /**
     * @return LengthAwarePaginator<int, Sale>
     */
    public function paginateSales(
        Organization $organization,
        Store $store,
        SalesReportFilters $filters,
        int $perPage,
    ): LengthAwarePaginator {
        return $this->rowQuery($organization, $store, $filters)->paginate(perPage: $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function saleRow(Sale $sale, string $timezone): array
    {
        return [
            'id' => $sale->id,
            'uuid' => $sale->uuid,
            'createdAt' => $sale->created_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'cashier' => [
                'id' => (int) $sale->cashier_id,
                'name' => $sale->cashier?->name,
            ],
            'total' => bcadd($this->money($sale->total), '0', self::SCALE),
            'paymentMethods' => $this->paymentMethods($sale),
        ];
    }

    /**
     * Streams the same filtered population as the JSON rows. Bounded on purpose:
     * a sync export is only safe while the range fits in memory.
     */
    public function exportCsv(
        Organization $organization,
        Store $store,
        SalesReportFilters $filters,
        string $timezone,
    ): string {
        $count = $this->baseQuery($organization, $store, $filters)->count();
        if ($count > self::CSV_ROW_LIMIT) {
            abort(422, 'Export range is too large ('.$count.' sales). Narrow the date range or filters to at most '.self::CSV_ROW_LIMIT.' sales.');
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            abort(500, 'Unable to build export.');
        }

        fputcsv($handle, ['sale_id', 'uuid', 'sold_at', 'cashier', 'total', 'payment_methods']);

        $this->rowQuery($organization, $store, $filters)
            ->lazyByIdDesc(500)
            ->each(function (Sale $sale) use ($handle, $timezone): void {
                $row = $this->saleRow($sale, $timezone);
                fputcsv($handle, [
                    $row['id'],
                    $row['uuid'],
                    $row['createdAt'],
                    $row['cashier']['name'] ?? '',
                    $row['total'],
                    implode('|', $row['paymentMethods']),
                ]);
            });

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    public function resolveTimezone(Organization $organization): string
    {
        $timezone = (string) ($organization->timezone ?? '');

        if ($timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return $timezone;
        }

        return (string) config('app.timezone', 'UTC');
    }

    /**
     * @return Builder<Sale>
     */
    private function baseQuery(Organization $organization, Store $store, SalesReportFilters $filters): Builder
    {
        $timezone = $this->resolveTimezone($organization);
        $appTimezone = config('app.timezone', 'UTC');

        // Inclusive calendar days in the organization timezone, converted to storage time
        // the same way the dashboard buckets "today" (AD: org calendar day, not server day).
        $rangeStart = CarbonImmutable::parse($filters->from, $timezone)
            ->startOfDay()
            ->setTimezone($appTimezone);
        $rangeEnd = CarbonImmutable::parse($filters->to, $timezone)
            ->startOfDay()
            ->addDay()
            ->setTimezone($appTimezone);

        $query = Sale::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->where('status', SaleStatus::Completed)
            ->where('created_at', '>=', $rangeStart)
            ->where('created_at', '<', $rangeEnd);

        if ($filters->cashierId !== null) {
            $query->where('cashier_id', $filters->cashierId);
        }

        if ($filters->paymentMethod !== null) {
            $query->whereHas('payments', fn ($payments) => $payments->where('method', $filters->paymentMethod));
        }

        if ($filters->productVariantId !== null) {
            $query->whereHas('items', fn ($items) => $items->where('product_variant_id', $filters->productVariantId));
        }

        if ($filters->categoryId !== null) {
            $query->whereHas(
                'items.productVariant.product',
                fn ($products) => $products->where('category_id', $filters->categoryId),
            );
        }

        return $query;
    }

    /**
     * @return Builder<Sale>
     */
    private function rowQuery(Organization $organization, Store $store, SalesReportFilters $filters): Builder
    {
        return $this->baseQuery($organization, $store, $filters)
            ->with(['cashier:id,name', 'payments:id,sale_id,method'])
            ->orderByDesc('id');
    }

    /**
     * @return Builder<SaleItem>
     */
    private function itemQuery(Organization $organization, Store $store, SalesReportFilters $filters): Builder
    {
        $query = SaleItem::query()
            ->whereIn('sale_id', $this->baseQuery($organization, $store, $filters)->select('sales.id'));

        if ($filters->productVariantId !== null) {
            $query->where('product_variant_id', $filters->productVariantId);
        }

        if ($filters->categoryId !== null) {
            $query->whereHas(
                'productVariant.product',
                fn ($products) => $products->where('category_id', $filters->categoryId),
            );
        }

        return $query;
    }

    /**
     * @param  array<int, array{salesCount: int, salesTotal: string}>  $byCashier
     * @return list<array<string, mixed>>
     */
    private function cashierRows(array $byCashier): array
    {
        if ($byCashier === []) {
            return [];
        }

        $names = User::query()
            ->whereIn('id', array_keys($byCashier))
            ->pluck('name', 'id');

        $rows = [];
        foreach ($byCashier as $cashierId => $totals) {
            $rows[] = [
                'cashierId' => $cashierId,
                'name' => $names[$cashierId] ?? null,
                'salesCount' => $totals['salesCount'],
                'salesTotal' => bcadd($totals['salesTotal'], '0', self::SCALE),
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $compare = bccomp($b['salesTotal'], $a['salesTotal'], self::SCALE);

            return $compare !== 0 ? $compare : $a['cashierId'] <=> $b['cashierId'];
        });

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function paymentMethods(Sale $sale): array
    {
        $methods = $sale->payments
            ->pluck('method')
            ->filter(fn ($method) => is_string($method) && $method !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        /** @var list<string> $methods */
        return $methods;
    }

    /**
     * Money is DECIMAL: keep it as a string and let bcmath do the arithmetic.
     */
    private function money(mixed $raw): string
    {
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return '0';
        }

        return (string) $raw;
    }
}
