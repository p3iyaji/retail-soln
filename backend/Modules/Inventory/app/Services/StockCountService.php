<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\StockCount;
use Modules\Organization\Models\Store;
use Modules\Organization\Models\Warehouse;
use Modules\Products\Models\ProductVariant;
use RuntimeException;

class StockCountService
{
    public function __construct(private readonly InventoryLedgerService $ledger) {}

    /**
     * @param  array{organizationId: int, locationType: string, locationId: int, userId: int, notes?: ?string, items: list<array{productVariantId: int, countedQuantity: string}>}  $input
     */
    public function complete(array $input): StockCount
    {
        $locationType = LocationType::from($input['locationType']);
        $this->assertLocation($input['organizationId'], $locationType, $input['locationId']);

        if ($input['items'] === []) {
            throw new RuntimeException('Stock count requires at least one item.');
        }

        return DB::transaction(function () use ($input, $locationType) {
            $count = StockCount::query()->create([
                'organization_id' => $input['organizationId'],
                'location_type' => $locationType->value,
                'location_id' => $input['locationId'],
                'status' => 'completed',
                'user_id' => $input['userId'],
                'notes' => $input['notes'] ?? null,
                'counted_at' => now(),
            ]);

            foreach ($input['items'] as $item) {
                $this->assertVariant($input['organizationId'], $item['productVariantId']);

                if (! preg_match('/^\d+(\.\d{1,4})?$/', $item['countedQuantity'])) {
                    throw new RuntimeException('Counted quantity must be a non-negative decimal.');
                }

                $balance = $this->ledger->balanceFor(
                    $input['organizationId'],
                    $locationType->value,
                    $input['locationId'],
                    $item['productVariantId'],
                );

                $system = $balance?->available ?? '0.0000';
                $variance = bcsub($item['countedQuantity'], (string) $system, 4);

                $countItem = $count->items()->create([
                    'product_variant_id' => $item['productVariantId'],
                    'system_quantity' => $system,
                    'counted_quantity' => $item['countedQuantity'],
                    'variance' => $variance,
                ]);

                if (bccomp($variance, '0', 4) !== 0) {
                    $movement = $this->ledger->post(new PostMovementData(
                        organizationId: $input['organizationId'],
                        locationType: $locationType,
                        locationId: $input['locationId'],
                        productVariantId: $item['productVariantId'],
                        type: MovementType::CountVariance,
                        quantity: $variance,
                        referenceType: 'stock_count',
                        referenceId: $count->id,
                        userId: $input['userId'],
                        notes: 'Count variance for item '.$countItem->id,
                    ));

                    $countItem->inventory_movement_id = $movement->id;
                    $countItem->save();
                }
            }

            return $count->fresh()->load('items');
        });
    }

    private function assertLocation(int $organizationId, LocationType $type, int $locationId): void
    {
        $exists = match ($type) {
            LocationType::Store => Store::query()
                ->where('organization_id', $organizationId)
                ->whereKey($locationId)
                ->exists(),
            LocationType::Warehouse => Warehouse::query()
                ->where('organization_id', $organizationId)
                ->whereKey($locationId)
                ->exists(),
        };

        if (! $exists) {
            throw new RuntimeException('Location not found for organization.');
        }
    }

    private function assertVariant(int $organizationId, int $variantId): void
    {
        $exists = ProductVariant::query()
            ->where('organization_id', $organizationId)
            ->whereKey($variantId)
            ->exists();

        if (! $exists) {
            throw new RuntimeException('Product variant not found for organization.');
        }
    }
}
