<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\BalanceBucket;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\StockTransfer;
use Modules\Organization\Models\Store;
use Modules\Organization\Models\Warehouse;
use Modules\Products\Models\ProductVariant;
use RuntimeException;

class StockTransferService
{
    public function __construct(private readonly InventoryLedgerService $ledger) {}

    /**
     * @param  array{
     *   organizationId: int,
     *   fromLocationType: string,
     *   fromLocationId: int,
     *   toLocationType: string,
     *   toLocationId: int,
     *   userId: int,
     *   notes?: ?string,
     *   items: list<array{productVariantId: int, quantity: string}>
     * }  $input
     */
    public function initiate(array $input): StockTransfer
    {
        $fromType = LocationType::from($input['fromLocationType']);
        $toType = LocationType::from($input['toLocationType']);

        $this->assertLocation($input['organizationId'], $fromType, $input['fromLocationId']);
        $this->assertLocation($input['organizationId'], $toType, $input['toLocationId']);

        if (
            $fromType === $toType
            && $input['fromLocationId'] === $input['toLocationId']
        ) {
            throw new RuntimeException('Source and destination must be different locations.');
        }

        if ($input['items'] === []) {
            throw new RuntimeException('Transfer requires at least one item.');
        }

        return DB::transaction(function () use ($input, $fromType, $toType) {
            $transfer = StockTransfer::query()->create([
                'organization_id' => $input['organizationId'],
                'from_location_type' => $fromType->value,
                'from_location_id' => $input['fromLocationId'],
                'to_location_type' => $toType->value,
                'to_location_id' => $input['toLocationId'],
                'status' => 'in_transit',
                'initiated_by' => $input['userId'],
                'notes' => $input['notes'] ?? null,
                'shipped_at' => now(),
            ]);

            foreach ($input['items'] as $item) {
                $this->assertVariant($input['organizationId'], $item['productVariantId']);

                if (! preg_match('/^\d+(\.\d{1,4})?$/', $item['quantity']) || bccomp($item['quantity'], '0', 4) <= 0) {
                    throw new RuntimeException('Transfer quantity must be a positive decimal.');
                }

                $balance = $this->ledger->getLockedBalance(
                    $input['organizationId'],
                    $fromType,
                    $input['fromLocationId'],
                    $item['productVariantId'],
                );

                if (bccomp((string) $balance->available, $item['quantity'], 4) < 0) {
                    throw new RuntimeException('Insufficient available stock at source location.');
                }

                $negQty = bcmul($item['quantity'], '-1', 4);

                $outMovement = $this->ledger->post(new PostMovementData(
                    organizationId: $input['organizationId'],
                    locationType: $fromType,
                    locationId: $input['fromLocationId'],
                    productVariantId: $item['productVariantId'],
                    type: MovementType::TransferOut,
                    quantity: $negQty,
                    bucket: BalanceBucket::Available,
                    referenceType: 'stock_transfer',
                    referenceId: $transfer->id,
                    userId: $input['userId'],
                    notes: 'Transfer out #'.$transfer->id,
                ));

                $transitMovement = $this->ledger->post(new PostMovementData(
                    organizationId: $input['organizationId'],
                    locationType: $fromType,
                    locationId: $input['fromLocationId'],
                    productVariantId: $item['productVariantId'],
                    type: MovementType::TransferOut,
                    quantity: $item['quantity'],
                    bucket: BalanceBucket::InTransit,
                    referenceType: 'stock_transfer',
                    referenceId: $transfer->id,
                    userId: $input['userId'],
                    notes: 'In transit for transfer #'.$transfer->id,
                ));

                $transfer->items()->create([
                    'product_variant_id' => $item['productVariantId'],
                    'quantity' => $item['quantity'],
                    'transfer_out_movement_id' => $outMovement->id,
                    'in_transit_movement_id' => $transitMovement->id,
                ]);
            }

            return $transfer->fresh()->load('items');
        });
    }

    public function receive(StockTransfer $transfer, int $userId): StockTransfer
    {
        if ($transfer->status !== 'in_transit') {
            throw new RuntimeException('Only in-transit transfers can be received.');
        }

        return DB::transaction(function () use ($transfer, $userId) {
            $transfer = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($transfer->status !== 'in_transit') {
                throw new RuntimeException('Only in-transit transfers can be received.');
            }

            $fromType = LocationType::from($transfer->from_location_type);
            $toType = LocationType::from($transfer->to_location_type);

            foreach ($transfer->items as $item) {
                $negQty = bcmul((string) $item->quantity, '-1', 4);

                $clearTransit = $this->ledger->post(new PostMovementData(
                    organizationId: $transfer->organization_id,
                    locationType: $fromType,
                    locationId: $transfer->from_location_id,
                    productVariantId: $item->product_variant_id,
                    type: MovementType::TransferIn,
                    quantity: $negQty,
                    bucket: BalanceBucket::InTransit,
                    referenceType: 'stock_transfer',
                    referenceId: $transfer->id,
                    userId: $userId,
                    notes: 'Clear in-transit for transfer #'.$transfer->id,
                ));

                $inMovement = $this->ledger->post(new PostMovementData(
                    organizationId: $transfer->organization_id,
                    locationType: $toType,
                    locationId: $transfer->to_location_id,
                    productVariantId: $item->product_variant_id,
                    type: MovementType::TransferIn,
                    quantity: (string) $item->quantity,
                    bucket: BalanceBucket::Available,
                    referenceType: 'stock_transfer',
                    referenceId: $transfer->id,
                    userId: $userId,
                    notes: 'Transfer in #'.$transfer->id,
                ));

                $item->in_transit_clear_movement_id = $clearTransit->id;
                $item->transfer_in_movement_id = $inMovement->id;
                $item->save();
            }

            $transfer->status = 'received';
            $transfer->received_by = $userId;
            $transfer->received_at = now();
            $transfer->save();

            return $transfer->fresh()->load('items');
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
