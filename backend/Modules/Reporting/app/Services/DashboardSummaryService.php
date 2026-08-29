<?php

namespace Modules\Reporting\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Modules\CustomerPortal\Services\PortalFulfilmentService;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\Inventory\Services\LowStockAlertService;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Sales\Enums\CashierSessionStatus;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Models\CashierSession;
use Modules\Sales\Models\Sale;
use Modules\Sync\Services\OversellExceptionService;

/**
 * Read-only KPI aggregation over existing sales/inventory/fulfilment/register facts.
 * Reporting is not a book of record (AD-12) and never mutates domain state.
 */
class DashboardSummaryService
{
    private const SCALE = 4;

    public function __construct(
        private readonly LowStockAlertService $lowStock,
        private readonly PortalFulfilmentService $fulfilment,
        private readonly OversellExceptionService $oversells,
    ) {}

    /**
     * Counts the caller is not permitted to see come back as null rather than 0,
     * so the client can hide the tile instead of showing a misleading zero.
     *
     * @return array<string, mixed>
     */
    public function forStore(Organization $organization, Store $store, User $user): array
    {
        $timezone = $this->resolveTimezone($organization);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $dayStart = $today->setTimezone(config('app.timezone', 'UTC'));
        $dayEnd = $today->addDay()->setTimezone(config('app.timezone', 'UTC'));

        $completedToday = Sale::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->where('status', SaleStatus::Completed)
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd);

        $canViewInventory = $user->hasPermission('inventory.view');
        $canApproveOversell = $user->hasPermission('inventory.approve-oversell');
        $canViewSales = $user->hasPermission('sales.view');

        return [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
            ],
            'date' => $today->toDateString(),
            'timezone' => $timezone,
            'currency' => $organization->default_currency,
            'todaySalesTotal' => $this->sumTotals($completedToday),
            'todaySalesCount' => (clone $completedToday)->count(),
            'pendingOrdersCount' => $this->fulfilment->queueCountForStore($organization->id, $store->id),
            'lowStockCount' => $canViewInventory
                ? $this->lowStock->count($organization->id, storeId: $store->id)
                : null,
            'openRegistersCount' => CashierSession::query()
                ->where('organization_id', $organization->id)
                ->where('store_id', $store->id)
                ->where('status', CashierSessionStatus::Open)
                ->count(),
            'needsAttention' => [
                'pendingOversellApprovals' => $canApproveOversell
                    ? $this->oversells->countPending($organization->id, $store->id)
                    : null,
                'readyForPickupOrders' => $this->fulfilment->queueCountForStore(
                    $organization->id,
                    $store->id,
                    [PortalOrderStatuses::READY_FOR_PICKUP],
                ),
                'heldSalesCount' => $canViewSales
                    ? Sale::query()
                        ->where('organization_id', $organization->id)
                        ->where('store_id', $store->id)
                        ->where('status', SaleStatus::Held)
                        ->count()
                    : null,
            ],
        ];
    }

    /**
     * Summed in PHP with bcmath: money is DECIMAL and SQL SUM() is float-typed on sqlite.
     *
     * @param  Builder<Sale>  $sales
     */
    private function sumTotals(Builder $sales): string
    {
        $total = '0';

        (clone $sales)
            ->select(['id', 'total'])
            ->lazyById(500)
            ->each(function (Sale $sale) use (&$total): void {
                $raw = $sale->total;
                if ($raw === null || $raw === '') {
                    return;
                }
                $amount = is_numeric($raw) ? (string) $raw : null;
                if ($amount === null) {
                    return;
                }
                $total = bcadd($total, $amount, self::SCALE);
            });

        return bcadd($total, '0', self::SCALE);
    }

    private function resolveTimezone(Organization $organization): string
    {
        $timezone = (string) ($organization->timezone ?? '');

        if ($timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return $timezone;
        }

        return (string) config('app.timezone', 'UTC');
    }
}
