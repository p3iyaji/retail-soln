<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\StockCountItem;
use Modules\Organization\Models\Store;
use Modules\Organization\Models\Warehouse;
use Modules\Products\Models\ProductVariant;
use RuntimeException;

class StockAdjustmentService
{
    public function __construct(private readonly InventoryLedgerService $ledger) {}

    /**
     * @param  array{organizationId: int, locationType: string, locationId: int, productVariantId: int, quantity: string, reason: string, userId: int}  $input
     */
    public function adjust(array $input): StockAdjustment
    {
        $locationType = LocationType::from($input['locationType']);
        $this->assertLocation($input['organizationId'], $locationType, $input['locationId']);
        $this->assertVariant($input['organizationId'], $input['productVariantId']);

        if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $input['quantity']) || bccomp($input['quantity'], '0', 4) === 0) {
            throw new RuntimeException('Adjustment quantity must be a non-zero decimal.');
        }

        return DB::transaction(function () use ($input, $locationType) {
            $adjustment = StockAdjustment::query()->create([
                'organization_id' => $input['organizationId'],
                'location_type' => $locationType->value,
                'location_id' => $input['locationId'],
                'product_variant_id' => $input['productVariantId'],
                'quantity' => $input['quantity'],
                'reason' => $input['reason'],
                'user_id' => $input['userId'],
            ]);

            $movement = $this->ledger->post(new PostMovementData(
                organizationId: $input['organizationId'],
                locationType: $locationType,
                locationId: $input['locationId'],
                productVariantId: $input['productVariantId'],
                type: MovementType::Adjustment,
                quantity: $input['quantity'],
                referenceType: 'stock_adjustment',
                referenceId: $adjustment->id,
                userId: $input['userId'],
                notes: $input['reason'],
            ));

            $adjustment->inventory_movement_id = $movement->id;
            $adjustment->save();

            return $adjustment->fresh()->load('movement');
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
