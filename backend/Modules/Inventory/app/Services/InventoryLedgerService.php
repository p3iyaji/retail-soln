<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\BalanceBucket;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Products\Models\ProductVariant;
use RuntimeException;

/**
 * Sole path for stock quantity mutation (AD-2).
 */
class InventoryLedgerService
{
    public function post(PostMovementData $data): InventoryMovement
    {
        if ($data->uuid !== null) {
            $existing = InventoryMovement::query()->where('uuid', $data->uuid)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $this->assertVariantBelongsToOrganization($data->organizationId, $data->productVariantId);
        $this->assertQuantity($data->quantity);

        return DB::transaction(function () use ($data) {
            if ($data->uuid !== null) {
                $existing = InventoryMovement::query()
                    ->where('uuid', $data->uuid)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $balance = $this->lockOrCreateBalance($data);
            $bucket = $data->bucket->value;
            $current = (string) $balance->{$bucket};
            $next = $this->addDecimal($current, $data->quantity);

            InventoryBalance::$allowQuantityMutation = true;
            try {
                $balance->{$bucket} = $next;
                $balance->save();
            } finally {
                InventoryBalance::$allowQuantityMutation = false;
            }

            return InventoryMovement::query()->create([
                'uuid' => $data->uuid,
                'organization_id' => $data->organizationId,
                'location_type' => $data->locationType->value,
                'location_id' => $data->locationId,
                'product_variant_id' => $data->productVariantId,
                'type' => $data->type->value,
                'quantity' => $data->quantity,
                'bucket' => $bucket,
                'unit_cost' => $data->unitCost,
                'reference_type' => $data->referenceType,
                'reference_id' => $data->referenceId,
                'user_id' => $data->userId,
                'notes' => $data->notes,
                'balance_after' => $next,
            ]);
        });
    }

    public function balanceFor(
        int $organizationId,
        string $locationType,
        int $locationId,
        int $productVariantId,
    ): ?InventoryBalance {
        return InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->where('location_type', $locationType)
            ->where('location_id', $locationId)
            ->where('product_variant_id', $productVariantId)
            ->first();
    }

    private function lockOrCreateBalance(PostMovementData $data): InventoryBalance
    {
        $balance = InventoryBalance::query()
            ->where('organization_id', $data->organizationId)
            ->where('location_type', $data->locationType->value)
            ->where('location_id', $data->locationId)
            ->where('product_variant_id', $data->productVariantId)
            ->lockForUpdate()
            ->first();

        if ($balance !== null) {
            return $balance;
        }

        InventoryBalance::$allowQuantityMutation = true;
        try {
            $created = InventoryBalance::query()->create([
                'organization_id' => $data->organizationId,
                'location_type' => $data->locationType->value,
                'location_id' => $data->locationId,
                'product_variant_id' => $data->productVariantId,
                'available' => '0.0000',
                'reserved' => '0.0000',
                'damaged' => '0.0000',
                'in_transit' => '0.0000',
            ]);
        } finally {
            InventoryBalance::$allowQuantityMutation = false;
        }

        return InventoryBalance::query()->whereKey($created->id)->lockForUpdate()->firstOrFail();
    }

    private function assertVariantBelongsToOrganization(int $organizationId, int $variantId): void
    {
        $exists = ProductVariant::query()
            ->where('id', $variantId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $exists) {
            throw new RuntimeException('Product variant not found for organization.');
        }
    }

    private function assertQuantity(string $quantity): void
    {
        if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $quantity)) {
            throw new RuntimeException('Quantity must be a decimal with up to 4 places.');
        }

        if (bccomp($quantity, '0', 4) === 0) {
            throw new RuntimeException('Quantity must be non-zero.');
        }
    }

    private function addDecimal(string $left, string $right): string
    {
        return bcadd($left, $right, 4);
    }
}
