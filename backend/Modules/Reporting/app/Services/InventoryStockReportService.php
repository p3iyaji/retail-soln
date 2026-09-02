<?php

namespace Modules\Reporting\Services;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Inventory\Enums\BalanceBucket;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Products\Models\ProductVariant;
use Modules\Reporting\Data\InventoryStockReportFilters;

/**
 * Read-only store inventory stock period report: as-of position + in-period movements.
 * Never mutates inventory (AD-2) and is not a book of record (AD-12).
 */
class InventoryStockReportService
{
    private const SCALE = 4;

    public const CSV_ROW_LIMIT = 10000;

    /** @var list<string> */
    private const BUCKETS = ['available', 'reserved', 'damaged', 'in_transit'];

    /**
     * Resolve inclusive org-timezone calendar bounds to app-TZ [start, endExclusive).
     *
     * @return array{start: CarbonImmutable, endExclusive: CarbonImmutable, periodStart: string, periodEnd: string, timezone: string}
     */
    public function resolvePeriodBounds(Organization $organization, InventoryStockReportFilters $filters): array
    {
        $timezone = $this->resolveTimezone($organization);
        $appTimezone = (string) config('app.timezone', 'UTC');
        $anchor = CarbonImmutable::parse($filters->anchorDate, $timezone)->startOfDay();

        [$periodStartLocal, $periodEndLocal] = match ($filters->period) {
            'day' => [$anchor, $anchor],
            'week' => [
                $anchor->startOfWeek(CarbonImmutable::MONDAY),
                $anchor->startOfWeek(CarbonImmutable::MONDAY)->addDays(6),
            ],
            'month' => [$anchor->startOfMonth(), $anchor->endOfMonth()->startOfDay()],
            'year' => [$anchor->startOfYear(), $anchor->endOfYear()->startOfDay()],
            default => abort(422, 'Invalid period preset.'),
        };

        $start = $periodStartLocal->setTimezone($appTimezone);
        $endExclusive = $periodEndLocal->addDay()->startOfDay()->setTimezone($appTimezone);

        return [
            'start' => $start,
            'endExclusive' => $endExclusive,
            'periodStart' => $periodStartLocal->toDateString(),
            'periodEnd' => $periodEndLocal->toDateString(),
            'timezone' => $timezone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReport(
        Organization $organization,
        Store $store,
        InventoryStockReportFilters $filters,
        int $page,
        int $perPage,
    ): array {
        $bounds = $this->resolvePeriodBounds($organization, $filters);
        $positions = $this->positionRows($organization, $store, $bounds['endExclusive']);
        $movements = $this->movementAggregates($organization, $store, $bounds['start'], $bounds['endExclusive']);

        $total = $positions->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $slice = $positions->slice(($page - 1) * $perPage, $perPage)->values();

        $onHandTotal = '0';
        foreach ($positions as $row) {
            $onHandTotal = bcadd($onHandTotal, $row['onHand'], self::SCALE);
        }

        return [
            'bounds' => $bounds,
            'summary' => [
                'periodStart' => $bounds['periodStart'],
                'periodEnd' => $bounds['periodEnd'],
                'timezone' => $bounds['timezone'],
                'positionSkuCount' => $total,
                'onHandTotal' => bcadd($onHandTotal, '0', self::SCALE),
                'movements' => $movements,
            ],
            'rows' => $slice->all(),
            'meta' => [
                'currentPage' => $page,
                'lastPage' => $lastPage,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function exportCsv(
        Organization $organization,
        Store $store,
        InventoryStockReportFilters $filters,
    ): string {
        $bounds = $this->resolvePeriodBounds($organization, $filters);
        $positions = $this->positionRows($organization, $store, $bounds['endExclusive']);

        if ($positions->count() > $this->csvRowLimit()) {
            abort(
                422,
                'Export is too large ('.$positions->count().' position rows). This store has too many balance rows to export synchronously (max '.$this->csvRowLimit().').',
            );
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            abort(500, 'Unable to build export.');
        }

        fputcsv($handle, [
            'product_variant_id',
            'sku',
            'name',
            'available',
            'reserved',
            'damaged',
            'in_transit',
            'on_hand',
        ]);

        foreach ($positions as $row) {
            fputcsv($handle, [
                $row['productVariantId'],
                $row['sku'] ?? '',
                $row['name'],
                $row['available'],
                $row['reserved'],
                $row['damaged'],
                $row['inTransit'],
                $row['onHand'],
            ]);
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        fclose($handle);
        if ($raw === false) {
            abort(500, 'Unable to build export.');
        }

        return (string) $raw;
    }

    /**
     * Hook for tests to exercise the real oversize guard without seeding 10k+ rows.
     */
    protected function csvRowLimit(): int
    {
        return self::CSV_ROW_LIMIT;
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
     * As-of period end: current bucket − Σ(post-end movements for that bucket).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function positionRows(
        Organization $organization,
        Store $store,
        CarbonImmutable $periodEndExclusive,
    ): Collection {
        $balances = $this->balanceQuery($organization, $store)
            ->orderBy('product_variant_id')
            ->get();

        if ($balances->isEmpty()) {
            return collect();
        }

        /** @var array<int, array<string, string>> $postEndByVariant */
        $postEndByVariant = [];

        InventoryMovement::query()
            ->where('organization_id', $organization->id)
            ->where('location_type', LocationType::Store->value)
            ->where('location_id', $store->id)
            ->where('created_at', '>=', $periodEndExclusive)
            ->whereIn('product_variant_id', $balances->pluck('product_variant_id'))
            ->select(['id', 'product_variant_id', 'bucket', 'quantity'])
            ->orderBy('id')
            ->lazyById(500)
            ->each(function (InventoryMovement $movement) use (&$postEndByVariant): void {
                $variantId = (int) $movement->product_variant_id;
                $bucket = $movement->bucket instanceof BalanceBucket
                    ? $movement->bucket->value
                    : (string) $movement->bucket;
                if (! in_array($bucket, self::BUCKETS, true)) {
                    return;
                }
                if (! isset($postEndByVariant[$variantId])) {
                    $postEndByVariant[$variantId] = array_fill_keys(self::BUCKETS, '0');
                }
                $postEndByVariant[$variantId][$bucket] = bcadd(
                    $postEndByVariant[$variantId][$bucket] ?? '0',
                    $this->qty($movement->quantity),
                    self::SCALE,
                );
            });

        $variants = ProductVariant::query()
            ->with('product:id,name')
            ->whereIn('id', $balances->pluck('product_variant_id'))
            ->get()
            ->keyBy('id');

        return $balances->map(function (InventoryBalance $balance) use ($postEndByVariant, $variants): array {
            $variantId = (int) $balance->product_variant_id;
            $post = $postEndByVariant[$variantId] ?? array_fill_keys(self::BUCKETS, '0');

            $available = bcsub($this->qty($balance->available), $post['available'] ?? '0', self::SCALE);
            $reserved = bcsub($this->qty($balance->reserved), $post['reserved'] ?? '0', self::SCALE);
            $damaged = bcsub($this->qty($balance->damaged), $post['damaged'] ?? '0', self::SCALE);
            $inTransit = bcsub($this->qty($balance->in_transit), $post['in_transit'] ?? '0', self::SCALE);
            $onHand = bcadd($available, $reserved, self::SCALE);

            /** @var ProductVariant|null $variant */
            $variant = $variants->get($variantId);

            return [
                'productVariantId' => $variantId,
                'sku' => $variant?->sku,
                'name' => trim(($variant?->product?->name ?? 'Unknown product').' — '.($variant?->name ?? '')),
                'available' => $available,
                'reserved' => $reserved,
                'damaged' => $damaged,
                'inTransit' => $inTransit,
                'onHand' => $onHand,
            ];
        })->values();
    }

    /**
     * In-period movement aggregates by type (+ bucket when a type spans buckets).
     *
     * @return list<array<string, mixed>>
     */
    private function movementAggregates(
        Organization $organization,
        Store $store,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEndExclusive,
    ): array {
        /** @var array<string, array<string, string>> $byTypeBucket type => [bucket => qty] */
        $byTypeBucket = [];

        InventoryMovement::query()
            ->where('organization_id', $organization->id)
            ->where('location_type', LocationType::Store->value)
            ->where('location_id', $store->id)
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEndExclusive)
            ->select(['id', 'type', 'bucket', 'quantity'])
            ->orderBy('id')
            ->lazyById(500)
            ->each(function (InventoryMovement $movement) use (&$byTypeBucket): void {
                $type = $movement->type instanceof \BackedEnum
                    ? $movement->type->value
                    : (string) $movement->type;
                $bucket = $movement->bucket instanceof BalanceBucket
                    ? $movement->bucket->value
                    : (string) $movement->bucket;

                if (! isset($byTypeBucket[$type])) {
                    $byTypeBucket[$type] = [];
                }
                $byTypeBucket[$type][$bucket] = bcadd(
                    $byTypeBucket[$type][$bucket] ?? '0',
                    $this->qty($movement->quantity),
                    self::SCALE,
                );
            });

        $rows = [];
        ksort($byTypeBucket);
        foreach ($byTypeBucket as $type => $buckets) {
            ksort($buckets);
            $multiBucket = count($buckets) > 1;
            foreach ($buckets as $bucket => $qty) {
                $row = [
                    'type' => $type,
                    'quantity' => bcadd($qty, '0', self::SCALE),
                ];
                if ($multiBucket) {
                    $row['bucket'] = $bucket;
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return Builder<InventoryBalance>
     */
    private function balanceQuery(Organization $organization, Store $store): Builder
    {
        return InventoryBalance::query()
            ->where('organization_id', $organization->id)
            ->where('location_type', LocationType::Store->value)
            ->where('location_id', $store->id);
    }

    private function qty(mixed $raw): string
    {
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return '0';
        }

        return (string) $raw;
    }
}
