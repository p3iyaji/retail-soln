<?php

namespace Modules\Inventory\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Authentication\Models\Permission;
use Modules\Authentication\Support\PermissionMatrix;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Notifications\Data\NotificationRecipient;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Services\NotificationService;
use Modules\Notifications\Support\NotificationTypes;

/**
 * Turns the read-only low-stock scan into staff notifications, one summary per
 * stocking location. Read-side only: never touches balances (AD-2).
 */
class LowStockNotifier
{
    private const RELATED_TYPE_PREFIX = 'inventory.location.';

    public function __construct(
        private readonly LowStockAlertService $alerts,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @return array{locations: int, notifications: int, skipped: int}
     */
    public function notify(int $organizationId, ?int $storeId = null, ?int $warehouseId = null): array
    {
        $locations = $this->summariseByLocation($organizationId, $storeId, $warehouseId);

        $created = 0;
        $skipped = 0;

        foreach ($locations as $location) {
            if ($this->withinCooldown($organizationId, $location['locationType'], $location['locationId'])) {
                $skipped++;

                continue;
            }

            $recipients = $this->recipientsFor($location['locationType'], $location['locationId']);
            if ($recipients === []) {
                $skipped++;

                continue;
            }

            $created += count($this->notifications->notify(
                type: NotificationTypes::LOW_STOCK,
                organizationId: $organizationId,
                recipients: $recipients,
                subject: $this->subjectFor($location),
                body: $this->bodyFor($location),
                storeId: $location['locationType'] === 'store' ? $location['locationId'] : null,
                payload: [
                    'locationType' => $location['locationType'],
                    'locationId' => $location['locationId'],
                    'total' => $location['total'],
                    'critical' => $location['critical'],
                    'warning' => $location['total'] - $location['critical'],
                    'skus' => implode(', ', $this->sampleSkus($organizationId, $location)),
                ],
                relatedType: self::RELATED_TYPE_PREFIX.$location['locationType'],
                relatedId: $location['locationId'],
            ));
        }

        return [
            'locations' => count($locations),
            'notifications' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return list<array{locationType: string, locationId: int, total: int, critical: int}>
     */
    private function summariseByLocation(int $organizationId, ?int $storeId, ?int $warehouseId): array
    {
        return $this->alerts->query($organizationId, $storeId, $warehouseId)
            ->without(['variant.product'])
            ->reorder()
            ->select('inventory_balances.location_type', 'inventory_balances.location_id')
            ->selectRaw('COUNT(*) as alert_total')
            ->selectRaw('SUM(CASE WHEN inventory_balances.available <= 0 THEN 1 ELSE 0 END) as alert_critical')
            ->groupBy('inventory_balances.location_type', 'inventory_balances.location_id')
            ->orderBy('inventory_balances.location_type')
            ->orderBy('inventory_balances.location_id')
            ->get()
            ->map(fn (InventoryBalance $row) => [
                'locationType' => (string) $row->location_type,
                'locationId' => (int) $row->location_id,
                'total' => (int) $row->getAttribute('alert_total'),
                'critical' => (int) $row->getAttribute('alert_critical'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{locationType: string, locationId: int, total: int, critical: int}  $location
     * @return list<string>
     */
    private function sampleSkus(int $organizationId, array $location): array
    {
        $limit = (int) config('notifications.low_stock.sample_limit', 10);

        return $this->alerts->query(
            $organizationId,
            $location['locationType'] === 'store' ? $location['locationId'] : null,
            $location['locationType'] === 'warehouse' ? $location['locationId'] : null,
        )
            ->limit($limit)
            ->get()
            ->map(fn (InventoryBalance $balance) => $balance->variant?->sku ?? (string) $balance->product_variant_id)
            ->values()
            ->all();
    }

    private function withinCooldown(int $organizationId, string $locationType, int $locationId): bool
    {
        $cooldown = (int) config('notifications.low_stock.cooldown_minutes', 720);
        if ($cooldown < 1) {
            return false;
        }

        return Notification::query()
            ->where('organization_id', $organizationId)
            ->where('type', NotificationTypes::LOW_STOCK)
            ->where('related_type', self::RELATED_TYPE_PREFIX.$locationType)
            ->where('related_id', $locationId)
            ->where('created_at', '>=', now()->subMinutes($cooldown))
            ->exists();
    }

    /**
     * Staff who can act on the shortfall: `inventory.view` holders with access
     * to the location. Warehouses have no per-user assignment, so only
     * org-wide roles are notified for them.
     *
     * @return list<NotificationRecipient>
     */
    private function recipientsFor(string $locationType, int $locationId): array
    {
        $roleCodes = $this->roleCodesWithInventoryView();
        if ($roleCodes === []) {
            return [];
        }

        $query = User::query()->whereIn('role', $roleCodes);

        if ($locationType === 'store') {
            $orgWide = array_values(array_intersect($roleCodes, PermissionMatrix::orgWideRoleCodes()));
            $query->where(function ($builder) use ($locationId, $orgWide): void {
                $builder->whereHas('stores', fn ($stores) => $stores->where('stores.id', $locationId));
                if ($orgWide !== []) {
                    $builder->orWhereIn('role', $orgWide);
                }
            });
        } else {
            $query->whereIn('role', PermissionMatrix::orgWideRoleCodes());
        }

        return $query->orderBy('id')->get()
            ->map(fn (User $user) => NotificationRecipient::staff(
                (int) $user->id,
                $user->email,
                $user->name,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function roleCodesWithInventoryView(): array
    {
        $permission = Permission::query()->where('name', 'inventory.view')->first();
        if ($permission === null) {
            return [];
        }

        /** @var Collection<int, string> $codes */
        $codes = $permission->roles()->pluck('code');

        return $codes->all();
    }

    /**
     * @param  array{locationType: string, locationId: int, total: int, critical: int}  $location
     */
    private function subjectFor(array $location): string
    {
        return sprintf(
            'Low stock: %d item%s at %s #%d',
            $location['total'],
            $location['total'] === 1 ? '' : 's',
            $location['locationType'],
            $location['locationId'],
        );
    }

    /**
     * @param  array{locationType: string, locationId: int, total: int, critical: int}  $location
     */
    private function bodyFor(array $location): string
    {
        return sprintf(
            '%d item%s at or below reorder level (%d out of stock, %d low). Review inventory and raise a purchase order if needed.',
            $location['total'],
            $location['total'] === 1 ? '' : 's',
            $location['critical'],
            $location['total'] - $location['critical'],
        );
    }
}
