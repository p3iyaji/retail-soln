<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Models\PortalOrderItem;
use Modules\CustomerPortal\Models\PortalReturnRequest;
use Modules\CustomerPortal\Models\PortalReturnRequestItem;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\CustomerPortal\Support\PortalReturnReasons;
use Modules\CustomerPortal\Support\PortalReturnRequestStatuses;
use Modules\Organization\Models\Organization;

class PortalReturnRequestService
{
    public function __construct(
        private readonly PortalOrganizationResolver $organizations,
    ) {}

    /**
     * @param  array{
     *   orderId: int,
     *   reason: string,
     *   notes?: ?string,
     *   items: list<array{orderItemId: int, qty: numeric-string|float|int|string}>,
     * }  $input
     * @return array{request: PortalReturnRequest, created: bool}
     */
    public function create(CustomerAccount $account, array $input, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw ValidationException::withMessages([
                'idempotencyKey' => ['Idempotency-Key must be between 1 and 64 characters.'],
            ]);
        }

        $organization = $this->organizations->resolve();
        $this->assertAccountOrg($account, $organization);

        $normalizedItems = $this->normalizeItems($input['items']);
        $fingerprint = [
            'orderId' => (int) $input['orderId'],
            'reason' => (string) $input['reason'],
            'notes' => $this->normalizeNotes($input['notes'] ?? null),
            'items' => $normalizedItems,
        ];

        $existing = PortalReturnRequest::query()
            ->where('organization_id', $organization->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            $this->assertIdempotentFingerprint($existing, $fingerprint);
            $this->assertOwned($existing, $account);

            return [
                'request' => $existing->load(['items.orderItem', 'order']),
                'created' => false,
            ];
        }

        try {
            return DB::transaction(function () use ($account, $organization, $input, $idempotencyKey, $fingerprint, $normalizedItems) {
                $lockedExisting = PortalReturnRequest::query()
                    ->where('organization_id', $organization->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($lockedExisting !== null) {
                    $this->assertIdempotentFingerprint($lockedExisting, $fingerprint);
                    $this->assertOwned($lockedExisting, $account);

                    return [
                        'request' => $lockedExisting->load(['items.orderItem', 'order']),
                        'created' => false,
                    ];
                }

                $order = PortalOrder::query()
                    ->with('items')
                    ->where('customer_account_id', $account->id)
                    ->where('organization_id', $organization->id)
                    ->where('id', (int) $input['orderId'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertEligibleForCreate($order);
                $this->assertNoOpenRequest($order);
                $this->assertLineQuantities($order, $normalizedItems);

                try {
                    $request = PortalReturnRequest::query()->create([
                        'organization_id' => $organization->id,
                        'customer_account_id' => $account->id,
                        'portal_order_id' => $order->id,
                        'status' => PortalReturnRequestStatuses::REQUESTED,
                        'reason' => (string) $input['reason'],
                        'notes' => $fingerprint['notes'],
                        'idempotency_key' => $idempotencyKey,
                        'request_fingerprint' => $fingerprint,
                    ]);
                } catch (QueryException $e) {
                    if ($this->isUniqueIdempotencyViolation($e)) {
                        $existingRequest = PortalReturnRequest::query()
                            ->where('organization_id', $organization->id)
                            ->where('idempotency_key', $idempotencyKey)
                            ->firstOrFail();
                        $this->assertIdempotentFingerprint($existingRequest, $fingerprint);
                        $this->assertOwned($existingRequest, $account);

                        return [
                            'request' => $existingRequest->load(['items.orderItem', 'order']),
                            'created' => false,
                        ];
                    }

                    throw $e;
                }

                foreach ($normalizedItems as $line) {
                    PortalReturnRequestItem::query()->create([
                        'portal_return_request_id' => $request->id,
                        'portal_order_item_id' => $line['orderItemId'],
                        'quantity' => $line['qty'],
                    ]);
                }

                return [
                    'request' => $request->load(['items.orderItem', 'order']),
                    'created' => true,
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueIdempotencyViolation($e)) {
                $existingRequest = PortalReturnRequest::query()
                    ->where('organization_id', $organization->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();
                $this->assertIdempotentFingerprint($existingRequest, $fingerprint);
                $this->assertOwned($existingRequest, $account);

                return [
                    'request' => $existingRequest->load(['items.orderItem', 'order']),
                    'created' => false,
                ];
            }

            throw $e;
        }
    }

    public function findOwned(CustomerAccount $account, int $id): PortalReturnRequest
    {
        return PortalReturnRequest::query()
            ->with(['items.orderItem', 'order'])
            ->where('customer_account_id', $account->id)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Whether the order can accept a new return request right now.
     */
    public function isEligible(PortalOrder $order): bool
    {
        if ($order->payment_status !== 'paid') {
            return false;
        }

        if ((string) $order->status !== PortalOrderStatuses::DELIVERED) {
            return false;
        }

        $hasOpen = PortalReturnRequest::query()
            ->where('portal_order_id', $order->id)
            ->whereIn('status', PortalReturnRequestStatuses::OPEN)
            ->exists();

        if ($hasOpen) {
            return false;
        }

        $remaining = $this->remainingReturnableByItem($order);

        foreach ($remaining as $qty) {
            if (bccomp($qty, '0.0000', 4) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: int, status: string, statusLabel: string, reason: string, createdAt: string|null}|null
     */
    public function activeRequestSummary(PortalOrder $order): ?array
    {
        $active = PortalReturnRequest::query()
            ->where('portal_order_id', $order->id)
            ->whereIn('status', PortalReturnRequestStatuses::OPEN)
            ->orderByDesc('id')
            ->first();

        if ($active === null) {
            return null;
        }

        return [
            'id' => $active->id,
            'status' => $active->status,
            'statusLabel' => PortalReturnRequestStatuses::label((string) $active->status),
            'reason' => $active->reason,
            'createdAt' => $active->created_at?->toIso8601String(),
        ];
    }

    /**
     * Latest closed (non-open) request for history when nothing is active.
     *
     * @return array{id: int, status: string, statusLabel: string, reason: string, createdAt: string|null}|null
     */
    public function latestRequestSummary(PortalOrder $order): ?array
    {
        $latest = PortalReturnRequest::query()
            ->where('portal_order_id', $order->id)
            ->whereNotIn('status', PortalReturnRequestStatuses::OPEN)
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return null;
        }

        return [
            'id' => $latest->id,
            'status' => $latest->status,
            'statusLabel' => PortalReturnRequestStatuses::label((string) $latest->status),
            'reason' => $latest->reason,
            'createdAt' => $latest->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, string> portal_order_item_id => remaining qty string
     */
    public function remainingReturnableByItem(PortalOrder $order): array
    {
        $order->loadMissing('items');

        $claimed = PortalReturnRequestItem::query()
            ->selectRaw('portal_order_item_id, SUM(quantity) as qty_sum')
            ->whereHas('returnRequest', function ($q) use ($order): void {
                $q->where('portal_order_id', $order->id)
                    ->whereIn('status', PortalReturnRequestStatuses::COUNTS_TOWARD_RETURNED_QTY);
            })
            ->groupBy('portal_order_item_id')
            ->pluck('qty_sum', 'portal_order_item_id');

        $remaining = [];
        foreach ($order->items as $item) {
            /** @var PortalOrderItem $item */
            $used = isset($claimed[$item->id])
                ? bcadd($this->decimalString($claimed[$item->id]), '0', 4)
                : '0.0000';
            $ordered = bcadd($this->decimalString($item->quantity), '0', 4);
            $left = bcsub($ordered, $used, 4);
            $remaining[$item->id] = bccomp($left, '0.0000', 4) < 0 ? '0.0000' : $left;
        }

        return $remaining;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(PortalReturnRequest $request): array
    {
        $request->loadMissing(['items.orderItem', 'order']);

        $completion = app(PortalReturnCompletionService::class);
        $refundTotal = $completion->refundTotalForRequest($request);

        return [
            'id' => $request->id,
            'orderId' => $request->portal_order_id,
            'orderNumber' => $request->order?->order_number,
            'status' => $request->status,
            'statusLabel' => PortalReturnRequestStatuses::label((string) $request->status),
            'reason' => $request->reason,
            'reasonLabel' => PortalReturnReasons::label((string) $request->reason),
            'notes' => $request->notes,
            'createdAt' => $request->created_at?->toIso8601String(),
            'completedAt' => $request->completed_at?->toIso8601String(),
            'refundTotal' => $refundTotal,
            'canComplete' => (string) $request->status === PortalReturnRequestStatuses::APPROVED,
            'items' => $request->items->map(function (PortalReturnRequestItem $item) use ($completion, $request) {
                $orderItem = $item->orderItem;
                $lineTotals = $completion->computeLineTotals($request);

                return [
                    'id' => $item->id,
                    'orderItemId' => $item->portal_order_item_id,
                    'qty' => (string) $item->quantity,
                    'lineTotal' => $item->line_total !== null
                        ? (string) $item->line_total
                        : ($lineTotals[$item->id]['line_total'] ?? '0.0000'),
                    'productName' => $orderItem?->product_name,
                    'variantName' => $orderItem?->variant_name,
                    'sku' => $orderItem?->sku,
                ];
            })->values()->all(),
            'refunds' => $completion->refundsToPayload($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toListItem(PortalReturnRequest $request): array
    {
        $request->loadMissing('order');

        return [
            'id' => $request->id,
            'orderId' => $request->portal_order_id,
            'orderNumber' => $request->order?->order_number,
            'status' => $request->status,
            'statusLabel' => PortalReturnRequestStatuses::label((string) $request->status),
            'reason' => $request->reason,
            'reasonLabel' => PortalReturnReasons::label((string) $request->reason),
            'createdAt' => $request->created_at?->toIso8601String(),
        ];
    }

    private function assertEligibleForCreate(PortalOrder $order): void
    {
        if ($order->payment_status !== 'paid') {
            throw ValidationException::withMessages([
                'orderId' => ['Only paid orders can request a return.'],
            ]);
        }

        $status = (string) $order->status;

        if (in_array($status, [
            PortalOrderStatuses::CANCELLED,
            PortalOrderStatuses::RETURNED,
            PortalOrderStatuses::REFUNDED,
        ], true)) {
            throw ValidationException::withMessages([
                'orderId' => ['This order cannot request a return.'],
            ]);
        }

        if ($status !== PortalOrderStatuses::DELIVERED) {
            throw ValidationException::withMessages([
                'orderId' => ['Return requests are only available after delivery.'],
            ]);
        }
    }

    private function assertNoOpenRequest(PortalOrder $order): void
    {
        $open = PortalReturnRequest::query()
            ->where('portal_order_id', $order->id)
            ->whereIn('status', PortalReturnRequestStatuses::OPEN)
            ->lockForUpdate()
            ->exists();

        if ($open) {
            throw ValidationException::withMessages([
                'orderId' => ['This order already has an open return request.'],
            ]);
        }
    }

    /**
     * @param  list<array{orderItemId: int, qty: string}>  $items
     */
    private function assertLineQuantities(PortalOrder $order, array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['Select at least one line to return.'],
            ]);
        }

        $remaining = $this->remainingReturnableByItem($order);
        $orderItemIds = $order->items->pluck('id')->all();

        foreach ($items as $index => $line) {
            $orderItemId = $line['orderItemId'];
            if (! in_array($orderItemId, $orderItemIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.orderItemId" => ['Order item does not belong to this order.'],
                ]);
            }

            $qty = $line['qty'];
            if (bccomp($qty, '0.0000', 4) <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.qty" => ['Quantity must be greater than zero.'],
                ]);
            }

            $available = $remaining[$orderItemId] ?? '0.0000';
            if (bccomp($qty, $available, 4) > 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.qty" => ['Quantity exceeds remaining returnable amount for this line.'],
                ]);
            }
        }
    }

    /**
     * @param  list<array{orderItemId: int, qty: mixed}>  $items
     * @return list<array{orderItemId: int, qty: string}>
     */
    private function normalizeItems(array $items): array
    {
        $byId = [];
        foreach ($items as $item) {
            $id = (int) $item['orderItemId'];
            $qty = $this->normalizeQty($item['qty'] ?? null);
            if (isset($byId[$id])) {
                $byId[$id] = bcadd($byId[$id], $qty, 4);
            } else {
                $byId[$id] = $qty;
            }
        }

        ksort($byId);

        $normalized = [];
        foreach ($byId as $id => $qty) {
            $normalized[] = [
                'orderItemId' => $id,
                'qty' => $qty,
            ];
        }

        return $normalized;
    }

    private function normalizeQty(mixed $qty): string
    {
        if ($qty === null || (is_string($qty) && trim($qty) === '')) {
            throw ValidationException::withMessages([
                'items' => ['Each line requires a quantity.'],
            ]);
        }

        if (is_bool($qty) || is_array($qty) || is_object($qty)) {
            throw ValidationException::withMessages([
                'items' => ['Quantity must be numeric.'],
            ]);
        }

        if (is_int($qty)) {
            $raw = (string) $qty;
        } elseif (is_string($qty)) {
            $raw = trim($qty);
        } else {
            $raw = trim((string) $qty);
            if (stripos($raw, 'e') !== false) {
                throw ValidationException::withMessages([
                    'items' => ['Quantity must be a positive decimal with at most 4 decimal places.'],
                ]);
            }
        }

        if (! preg_match('/^\d+(\.\d{1,4})?$/', $raw)) {
            throw ValidationException::withMessages([
                'items' => ['Quantity must be a positive decimal with at most 4 decimal places.'],
            ]);
        }

        return bcadd($raw, '0', 4);
    }

    /**
     * Coerce Eloquent/DB numerics to a digit string without float formatting helpers.
     */
    private function decimalString(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return trim($value);
        }

        $asString = trim((string) $value);
        if ($asString === '' || stripos($asString, 'e') !== false) {
            throw ValidationException::withMessages([
                'items' => ['Quantity must be numeric.'],
            ]);
        }

        return $asString;
    }

    private function normalizeNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $trimmed = trim($notes);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    private function assertIdempotentFingerprint(PortalReturnRequest $request, array $fingerprint): void
    {
        $stored = $request->request_fingerprint ?? [];

        foreach (['orderId', 'reason', 'notes', 'items'] as $key) {
            $left = $stored[$key] ?? null;
            $right = $fingerprint[$key] ?? null;
            if ($left != $right) {
                throw ValidationException::withMessages([
                    'idempotencyKey' => ['Idempotency-Key was already used with a different request body.'],
                ]);
            }
        }
    }

    private function assertOwned(PortalReturnRequest $request, CustomerAccount $account): void
    {
        if ((int) $request->customer_account_id !== (int) $account->id) {
            throw ValidationException::withMessages([
                'idempotencyKey' => ['Idempotency-Key was already used with a different request body.'],
            ]);
        }
    }

    private function assertAccountOrg(CustomerAccount $account, Organization $organization): void
    {
        if ((int) $account->organization_id !== (int) $organization->id) {
            throw ValidationException::withMessages([
                'organization' => ['Account does not belong to this organization.'],
            ]);
        }
    }

    private function isUniqueIdempotencyViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'idempotency_key')
            || str_contains($message, 'portal_return_requests_organization_id_idempotency_key');
    }
}
