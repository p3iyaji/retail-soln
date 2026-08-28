<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Models\Sale;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class SaleVoidService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly CashierSessionService $sessions,
    ) {}

    public function void(Sale $sale, int $userId, ?string $reason = null): Sale
    {
        if ($sale->status === SaleStatus::Voided) {
            throw new RuntimeException('Sale is already voided.');
        }

        return DB::transaction(function () use ($sale, $userId, $reason) {
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SaleStatus::Voided) {
                throw new RuntimeException('Sale is already voided.');
            }

            if ($locked->status === SaleStatus::Completed) {
                $this->reverseInventory($locked, $userId, $reason);
                $locked->invoice?->update(['status' => 'voided']);
                $this->sessions->recordVoidCashRefunds($locked, $userId);
            }

            $locked->update([
                'status' => SaleStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $userId,
                'notes' => $reason !== null && $reason !== ''
                    ? trim(($locked->notes ? $locked->notes.' — ' : '').$reason)
                    : $locked->notes,
            ]);

            return $locked->fresh([
                'items.productVariant',
                'payments',
                'customer',
                'store',
                'invoice',
            ]);
        });
    }

    private function reverseInventory(Sale $sale, int $userId, ?string $reason): void
    {
        $sale->loadMissing('items');

        $lineIndex = 0;
        foreach ($sale->items as $item) {
            $posQty = bcadd((string) $item->qty, '0', 4);
            $movementUuid = Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                'sale-void:'.$sale->id.':'.$lineIndex,
            )->toString();

            $movement = $this->ledger->post(new PostMovementData(
                organizationId: $sale->organization_id,
                locationType: LocationType::Store,
                locationId: $sale->store_id,
                productVariantId: $item->product_variant_id,
                type: MovementType::Return,
                quantity: $posQty,
                referenceType: 'sale_void',
                referenceId: $sale->id,
                userId: $userId,
                notes: $reason ?? 'Void sale #'.$sale->id,
                uuid: $movementUuid,
            ));

            unset($movement);
            $lineIndex++;
        }
    }
}
