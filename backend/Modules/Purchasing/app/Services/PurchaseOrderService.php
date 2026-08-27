<?php

namespace Modules\Purchasing\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Models\Store;
use Modules\Products\Models\ProductVariant;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;
use Modules\Settings\Models\Setting;
use RuntimeException;

class PurchaseOrderService
{
    /**
     * @param  array{
     *   organizationId: int,
     *   storeId: int,
     *   supplierId: int,
     *   userId: int,
     *   notes?: ?string,
     *   items: list<array{productVariantId: int, qty: string, unitCost: string}>
     * }  $input
     */
    public function create(array $input): PurchaseOrder
    {
        if ($input['items'] === []) {
            throw new RuntimeException('Purchase order requires at least one line item.');
        }

        $this->assertStore($input['organizationId'], $input['storeId']);
        $this->assertSupplier($input['organizationId'], $input['supplierId']);

        return DB::transaction(function () use ($input) {
            $po = $this->createWithUniqueNumber($input);

            $this->syncItems($po, $input['organizationId'], $input['items']);

            return $po->fresh(['items.productVariant', 'supplier', 'store']);
        });
    }

    /**
     * @param  array{
     *   organizationId: int,
     *   storeId: int,
     *   supplierId: int,
     *   userId: int,
     *   notes?: ?string,
     * }  $input
     */
    private function createWithUniqueNumber(array $input): PurchaseOrder
    {
        $attempts = 0;
        while (true) {
            try {
                return PurchaseOrder::query()->create([
                    'organization_id' => $input['organizationId'],
                    'store_id' => $input['storeId'],
                    'supplier_id' => $input['supplierId'],
                    'number' => $this->nextNumber($input['organizationId']),
                    'status' => PurchaseOrderStatus::Draft,
                    'notes' => $input['notes'] ?? null,
                    'total_amount' => '0.0000',
                    'created_by' => $input['userId'],
                ]);
            } catch (QueryException $e) {
                $attempts++;
                if ($attempts >= 5 || ! $this->isUniqueNumberConflict($e)) {
                    throw $e;
                }
            }
        }
    }

    private function isUniqueNumberConflict(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || (string) $e->getCode() === '23000';
    }

    /**
     * @param  array{
     *   storeId?: int,
     *   supplierId?: int,
     *   notes?: ?string,
     *   items?: list<array{productVariantId: int, qty: string, unitCost: string}>
     * }  $input
     */
    public function update(PurchaseOrder $po, int $organizationId, array $input): PurchaseOrder
    {
        if (! $po->status->isEditable()) {
            throw new RuntimeException('Only draft or rejected purchase orders can be updated.');
        }

        return DB::transaction(function () use ($po, $organizationId, $input) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if (! $po->status->isEditable()) {
                throw new RuntimeException('Only draft or rejected purchase orders can be updated.');
            }

            if (array_key_exists('storeId', $input)) {
                $this->assertStore($organizationId, $input['storeId']);
                $po->store_id = $input['storeId'];
            }
            if (array_key_exists('supplierId', $input)) {
                $this->assertSupplier($organizationId, $input['supplierId']);
                $po->supplier_id = $input['supplierId'];
            }
            if (array_key_exists('notes', $input)) {
                $po->notes = $input['notes'];
            }
            $po->save();

            if (array_key_exists('items', $input)) {
                if ($input['items'] === []) {
                    throw new RuntimeException('Purchase order requires at least one line item.');
                }
                $this->syncItems($po, $organizationId, $input['items']);
            }

            return $po->fresh(['items.productVariant', 'supplier', 'store']);
        });
    }

    public function submit(PurchaseOrder $po): PurchaseOrder
    {
        if (! $po->status->canSubmit()) {
            throw new RuntimeException('Only draft or rejected purchase orders can be submitted.');
        }

        return DB::transaction(function () use ($po) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if (! $po->status->canSubmit()) {
                throw new RuntimeException('Only draft or rejected purchase orders can be submitted.');
            }

            if ($po->items()->count() === 0) {
                throw new RuntimeException('Purchase order requires at least one line item.');
            }

            $po->status = PurchaseOrderStatus::PendingApproval;
            $po->submitted_at = now();
            $po->approved_by = null;
            $po->approved_at = null;
            $po->rejected_by = null;
            $po->rejected_at = null;
            $po->rejection_reason = null;
            $po->save();

            return $po->fresh(['items.productVariant', 'supplier', 'store']);
        });
    }

    public function approve(PurchaseOrder $po, int $userId): PurchaseOrder
    {
        $this->assertDualControl($po, $userId);

        if (! $po->status->canApproveOrReject()) {
            throw new RuntimeException('Only purchase orders pending approval can be approved.');
        }

        return DB::transaction(function () use ($po, $userId) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if (! $po->status->canApproveOrReject()) {
                throw new RuntimeException('Only purchase orders pending approval can be approved.');
            }

            $this->assertDualControl($po, $userId);

            $po->status = PurchaseOrderStatus::Approved;
            $po->approved_by = $userId;
            $po->approved_at = now();
            $po->rejected_by = null;
            $po->rejected_at = null;
            $po->rejection_reason = null;
            $po->save();

            return $po->fresh(['items.productVariant', 'supplier', 'store']);
        });
    }

    public function reject(PurchaseOrder $po, int $userId, ?string $reason = null): PurchaseOrder
    {
        $this->assertDualControl($po, $userId);

        if (! $po->status->canApproveOrReject()) {
            throw new RuntimeException('Only purchase orders pending approval can be rejected.');
        }

        return DB::transaction(function () use ($po, $userId, $reason) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if (! $po->status->canApproveOrReject()) {
                throw new RuntimeException('Only purchase orders pending approval can be rejected.');
            }

            $this->assertDualControl($po, $userId);

            $po->status = PurchaseOrderStatus::Rejected;
            $po->rejected_by = $userId;
            $po->rejected_at = now();
            $po->rejection_reason = $reason;
            $po->save();

            return $po->fresh(['items.productVariant', 'supplier', 'store']);
        });
    }

    public function deleteDraft(PurchaseOrder $po): void
    {
        DB::transaction(function () use ($po): void {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if ($po->status !== PurchaseOrderStatus::Draft) {
                throw new RuntimeException('Only draft purchase orders can be deleted.');
            }

            $po->delete();
        });
    }

    private function assertDualControl(PurchaseOrder $po, int $userId): void
    {
        if (! $this->isDualControlEnabled($po->organization_id)) {
            return;
        }

        if ((int) $po->created_by === $userId) {
            throw new DualControlViolationException(
                'Dual-control is enabled: the creator cannot approve or reject their own purchase order.'
            );
        }
    }

    public function isDualControlEnabled(int $organizationId): bool
    {
        $setting = Setting::query()
            ->where('organization_id', $organizationId)
            ->whereNull('store_id')
            ->where('key', 'purchase.dual_control')
            ->first();

        if ($setting === null || $setting->value === null) {
            return false;
        }

        $value = $setting->value;
        if (is_array($value)) {
            if (array_key_exists('data', $value)) {
                return filter_var($value['data'], FILTER_VALIDATE_BOOLEAN);
            }
            if (array_key_exists('enabled', $value)) {
                return filter_var($value['enabled'], FILTER_VALIDATE_BOOLEAN);
            }

            $first = reset($value);

            return $first !== false && filter_var($first, FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function nextNumber(int $organizationId): string
    {
        $year = now()->format('Y');
        $prefix = "PO-{$year}-";

        $numbers = PurchaseOrder::query()
            ->where('organization_id', $organizationId)
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('number');

        $maxSeq = 0;
        foreach ($numbers as $number) {
            if (is_string($number) && preg_match('/^PO-\d{4}-(\d+)$/', $number, $m)) {
                $maxSeq = max($maxSeq, (int) $m[1]);
            }
        }

        return $prefix.str_pad((string) ($maxSeq + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<array{productVariantId: int, qty: string, unitCost: string}>  $items
     */
    private function syncItems(PurchaseOrder $po, int $organizationId, array $items): void
    {
        $po->items()->delete();

        $total = '0.0000';
        foreach ($items as $item) {
            $this->assertVariant($organizationId, $item['productVariantId']);
            $this->assertPositiveDecimal($item['qty'], 'qty');
            $this->assertNonNegativeDecimal($item['unitCost'], 'unitCost');

            $lineTotal = bcmul($item['qty'], $item['unitCost'], 4);
            $total = bcadd($total, $lineTotal, 4);

            $po->items()->create([
                'organization_id' => $organizationId,
                'product_variant_id' => $item['productVariantId'],
                'qty' => $item['qty'],
                'unit_cost' => $item['unitCost'],
                'line_total' => $lineTotal,
            ]);
        }

        $po->total_amount = $total;
        $po->save();
    }

    private function assertStore(int $organizationId, int $storeId): void
    {
        $exists = Store::query()
            ->where('organization_id', $organizationId)
            ->whereKey($storeId)
            ->exists();

        if (! $exists) {
            throw new RuntimeException('Store not found for organization.');
        }
    }

    private function assertSupplier(int $organizationId, int $supplierId): void
    {
        $exists = Supplier::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereKey($supplierId)
            ->exists();

        if (! $exists) {
            throw new RuntimeException('Active supplier not found for organization.');
        }
    }

    private function assertVariant(int $organizationId, int $variantId): void
    {
        $exists = ProductVariant::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereKey($variantId)
            ->exists();

        if (! $exists) {
            throw new RuntimeException('Active product variant not found for organization.');
        }
    }

    private function assertPositiveDecimal(string $value, string $field): void
    {
        if (! preg_match('/^\d+(\.\d{1,4})?$/', $value) || bccomp($value, '0', 4) <= 0) {
            throw new RuntimeException("{$field} must be a positive decimal.");
        }
    }

    private function assertNonNegativeDecimal(string $value, string $field): void
    {
        if (! preg_match('/^\d+(\.\d{1,4})?$/', $value) || bccomp($value, '0', 4) < 0) {
            throw new RuntimeException("{$field} must be a non-negative decimal.");
        }
    }
}
