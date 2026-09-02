<?php

namespace Modules\CustomerPortal\Services;

use App\Services\Payments\PaymentService;
use App\Services\Payments\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Models\PortalOrderItem;
use Modules\CustomerPortal\Models\PortalRefund;
use Modules\CustomerPortal\Models\PortalReturnRequest;
use Modules\CustomerPortal\Models\PortalReturnRequestItem;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\CustomerPortal\Support\PortalReturnRequestStatuses;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Sales\Enums\PaymentMethod;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class PortalReturnCompletionService
{
    /** @var list<string> */
    public const REFUND_PROVIDERS = ['manual', 'stripe', 'paystack'];

    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly PaymentService $payments,
        private readonly PortalReturnRequestService $returns,
    ) {}

    /**
     * @param  list<array{
     *   provider: string,
     *   method?: ?string,
     *   amount: string,
     *   reference?: ?string,
     * }>  $refunds
     * @return array{request: PortalReturnRequest, created: bool}
     */
    public function complete(
        PortalReturnRequest $request,
        int $storeId,
        int $userId,
        string $idempotencyKey,
        array $refunds,
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw ValidationException::withMessages([
                'idempotencyKey' => ['Idempotency-Key must be between 1 and 64 characters.'],
            ]);
        }

        $existing = PortalReturnRequest::query()
            ->where('organization_id', $request->organization_id)
            ->where('complete_idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ((int) $existing->id !== (int) $request->id) {
                throw ValidationException::withMessages([
                    'idempotencyKey' => ['Idempotency-Key was already used with a different return request.'],
                ]);
            }

            return [
                'request' => $existing->load(['items.orderItem', 'order.store', 'account.customer', 'refunds']),
                'created' => false,
            ];
        }

        try {
            return DB::transaction(function () use ($request, $storeId, $userId, $idempotencyKey, $refunds) {
                $lockedExisting = PortalReturnRequest::query()
                    ->where('organization_id', $request->organization_id)
                    ->where('complete_idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($lockedExisting !== null) {
                    return [
                        'request' => $lockedExisting->load(['items.orderItem', 'order.store', 'account.customer', 'refunds']),
                        'created' => false,
                    ];
                }

                $locked = PortalReturnRequest::query()
                    ->with(['items.orderItem', 'order.items', 'order.store'])
                    ->whereKey($request->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((string) $locked->status !== PortalReturnRequestStatuses::APPROVED) {
                    throw ValidationException::withMessages([
                        'status' => ['Only approved return requests can be completed.'],
                    ]);
                }

                $order = $locked->order;
                if ($order === null || (int) $order->store_id !== $storeId) {
                    throw ValidationException::withMessages([
                        'store' => ['Return request does not belong to this store.'],
                    ]);
                }

                if ($order->payment_status !== 'paid') {
                    throw ValidationException::withMessages([
                        'orderId' => ['Only paid orders can be refunded.'],
                    ]);
                }

                $lineTotals = $this->computeLineTotals($locked);
                $returnTotal = '0.0000';
                foreach ($lineTotals as $line) {
                    $returnTotal = bcadd($returnTotal, $line['line_total'], 4);
                }

                $refundTotal = '0.0000';
                foreach ($refunds as $refund) {
                    $refundTotal = bcadd($refundTotal, bcadd((string) $refund['amount'], '0', 4), 4);
                }

                if (bccomp($refundTotal, $returnTotal, 4) !== 0) {
                    throw ValidationException::withMessages([
                        'refunds' => ['Refund total must equal return total ('.$returnTotal.').'],
                    ]);
                }

                $lineIndex = 0;
                foreach ($locked->items as $item) {
                    /** @var PortalReturnRequestItem $item */
                    $orderItem = $item->orderItem;
                    if ($orderItem === null) {
                        throw new RuntimeException('Return line missing order item.');
                    }

                    $qty = bcadd((string) $item->quantity, '0', 4);
                    $movementUuid = Uuid::uuid5(
                        Uuid::NAMESPACE_URL,
                        'portal-return:'.$idempotencyKey.':'.$lineIndex,
                    )->toString();

                    $movement = $this->ledger->post(new PostMovementData(
                        organizationId: (int) $locked->organization_id,
                        locationType: LocationType::Store,
                        locationId: $storeId,
                        productVariantId: (int) $orderItem->product_variant_id,
                        type: MovementType::Return,
                        quantity: $qty,
                        referenceType: 'portal_return_request',
                        referenceId: (int) $locked->id,
                        userId: $userId,
                        notes: 'Portal return for order '.$order->order_number,
                        uuid: $movementUuid,
                    ));

                    $item->inventory_movement_id = $movement->id;
                    $item->line_total = $lineTotals[$item->id]['line_total'] ?? '0.0000';
                    $item->save();

                    $lineIndex++;
                }

                foreach ($refunds as $refundInput) {
                    $provider = strtolower((string) $refundInput['provider']);
                    $amount = bcadd((string) $refundInput['amount'], '0', 4);
                    $method = $this->resolveRefundMethod($provider, $refundInput['method'] ?? null);

                    $gatewayResult = null;
                    if (in_array($provider, ['stripe', 'paystack'], true)) {
                        $gatewayResult = $this->payments->refund([
                            'provider' => $provider,
                            'originalReference' => (string) ($order->payment_reference ?? ''),
                            'amount' => $amount,
                            'currency' => $order->organization?->default_currency ?? null,
                            'reason' => 'portal_return_request',
                            'metadata' => [
                                'portalReturnRequestId' => $locked->id,
                                'portalOrderId' => $order->id,
                            ],
                        ]);
                    } elseif ($provider !== 'manual') {
                        throw ValidationException::withMessages([
                            'refunds' => ['Unsupported refund provider: '.$provider],
                        ]);
                    }

                    $status = PortalRefund::STATUS_COMPLETED;
                    $gatewayReference = null;
                    if ($gatewayResult !== null) {
                        $status = $gatewayResult->status === 'completed'
                            ? PortalRefund::STATUS_COMPLETED
                            : PortalRefund::STATUS_PENDING;
                        $gatewayReference = $gatewayResult->gatewayReference ?? $gatewayResult->reference;
                    }

                    PortalRefund::query()->create([
                        'organization_id' => $locked->organization_id,
                        'portal_return_request_id' => $locked->id,
                        'portal_order_id' => $order->id,
                        'provider' => $provider,
                        'method' => $method,
                        'amount' => $amount,
                        'status' => $status,
                        'reference' => $refundInput['reference'] ?? null,
                        'gateway_reference' => $gatewayReference,
                        'processed_by' => $userId,
                    ]);
                }

                $locked->status = PortalReturnRequestStatuses::COMPLETED;
                $locked->refund_total = $refundTotal;
                $locked->completed_at = now();
                $locked->completed_by = $userId;
                $locked->complete_idempotency_key = $idempotencyKey;
                $locked->save();

                $this->syncOrderStatusAfterCompletion($order->fresh(['items']));

                return [
                    'request' => $locked->fresh(['items.orderItem', 'order.store', 'account.customer', 'refunds']),
                    'created' => true,
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueCompleteIdempotencyViolation($e)) {
                $existingRequest = PortalReturnRequest::query()
                    ->where('organization_id', $request->organization_id)
                    ->where('complete_idempotency_key', $idempotencyKey)
                    ->firstOrFail();

                return [
                    'request' => $existingRequest->load(['items.orderItem', 'order.store', 'account.customer', 'refunds']),
                    'created' => false,
                ];
            }

            throw $e;
        }
    }

    public function applyRefundWebhook(WebhookEvent $event): ?PortalRefund
    {
        if (! in_array($event->status, ['refunded', 'refund_failed'], true)) {
            return null;
        }

        $refund = PortalRefund::query()
            ->where('gateway_reference', $event->reference)
            ->orWhere('reference', $event->reference)
            ->orderByDesc('id')
            ->first();

        if ($refund === null) {
            return null;
        }

        $nextStatus = $event->status === 'refunded'
            ? PortalRefund::STATUS_COMPLETED
            : PortalRefund::STATUS_FAILED;

        if ((string) $refund->status === $nextStatus) {
            return $refund;
        }

        $refund->status = $nextStatus;
        $refund->save();

        return $refund->fresh();
    }

    /**
     * @return array<int, array{line_total: string}>
     */
    public function computeLineTotals(PortalReturnRequest $request): array
    {
        $request->loadMissing(['items.orderItem', 'order.items']);
        $orderItems = $request->order?->items->keyBy('id') ?? collect();

        $totals = [];
        foreach ($request->items as $item) {
            /** @var PortalOrderItem|null $orderItem */
            $orderItem = $orderItems->get($item->portal_order_item_id);
            if ($orderItem === null) {
                continue;
            }

            $totals[$item->id] = [
                'line_total' => $this->prorateLine($orderItem, (string) $item->quantity),
            ];
        }

        return $totals;
    }

    public function refundTotalForRequest(PortalReturnRequest $request): string
    {
        $total = '0.0000';
        foreach ($this->computeLineTotals($request) as $line) {
            $total = bcadd($total, $line['line_total'], 4);
        }

        return $total;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function refundsToPayload(PortalReturnRequest $request): array
    {
        $request->loadMissing('refunds');

        return $request->refunds->map(fn (PortalRefund $refund) => [
            'id' => $refund->id,
            'provider' => $refund->provider,
            'method' => $refund->method,
            'amount' => (string) $refund->amount,
            'status' => $refund->status,
            'statusLabel' => $this->refundStatusLabel((string) $refund->status),
            'reference' => $refund->reference,
            'gatewayReference' => $refund->gateway_reference,
            'createdAt' => $refund->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function orderRefundsPayload(PortalOrder $order): array
    {
        return PortalRefund::query()
            ->where('portal_order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(fn (PortalRefund $refund) => [
                'id' => $refund->id,
                'returnRequestId' => $refund->portal_return_request_id,
                'provider' => $refund->provider,
                'method' => $refund->method,
                'amount' => (string) $refund->amount,
                'status' => $refund->status,
                'statusLabel' => $this->refundStatusLabel((string) $refund->status),
                'reference' => $refund->reference,
                'createdAt' => $refund->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function syncOrderStatusAfterCompletion(PortalOrder $order): void
    {
        $remaining = $this->returns->remainingReturnableByItem($order);
        $allReturned = true;
        foreach ($remaining as $qty) {
            if (bccomp($qty, '0.0000', 4) > 0) {
                $allReturned = false;
                break;
            }
        }

        $refundedAmount = PortalRefund::query()
            ->where('portal_order_id', $order->id)
            ->where('status', PortalRefund::STATUS_COMPLETED)
            ->get()
            ->reduce(fn (string $carry, PortalRefund $r) => bcadd($carry, (string) $r->amount, 4), '0.0000');

        $orderTotal = bcadd((string) $order->total, '0', 4);

        if ($allReturned && bccomp($refundedAmount, $orderTotal, 4) >= 0) {
            $order->status = PortalOrderStatuses::REFUNDED;
        } elseif ($allReturned || bccomp($refundedAmount, '0.0000', 4) > 0) {
            $order->status = PortalOrderStatuses::RETURNED;
        }

        $order->save();
    }

    private function prorateLine(PortalOrderItem $item, string $returnQty): string
    {
        $soldQty = bcadd((string) $item->quantity, '0', 4);
        if (bccomp($soldQty, '0', 4) <= 0) {
            return '0.0000';
        }

        $ratio = bcdiv($returnQty, $soldQty, 8);

        return bcmul(bcadd((string) $item->line_total, '0', 4), $ratio, 4);
    }

    private function resolveRefundMethod(string $provider, ?string $method): string
    {
        if ($provider === 'manual') {
            $resolved = $method ?? PaymentMethod::Bank->value;
            if (! in_array($resolved, PaymentMethod::values(), true)) {
                throw ValidationException::withMessages([
                    'refunds' => ['Invalid manual refund method.'],
                ]);
            }

            return $resolved;
        }

        return PaymentMethod::Card->value;
    }

    private function refundStatusLabel(string $status): string
    {
        return match ($status) {
            PortalRefund::STATUS_PENDING => 'Pending',
            PortalRefund::STATUS_COMPLETED => 'Completed',
            PortalRefund::STATUS_FAILED => 'Failed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function isUniqueCompleteIdempotencyViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'complete_idempotency_key')
            || str_contains($message, 'portal_return_req_org_complete_idem');
    }
}
