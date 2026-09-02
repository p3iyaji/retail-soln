<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\PortalReturnRequest;
use Modules\CustomerPortal\Support\PortalReturnRequestStatuses;

class PortalReturnRequestStaffService
{
    public function __construct(
        private readonly PortalReturnRequestService $returns,
        private readonly PortalReturnCompletionService $completions,
    ) {}

    /**
     * @param  list<string>|null  $statuses
     * @return LengthAwarePaginator<int, PortalReturnRequest>
     */
    public function listForStore(
        int $organizationId,
        int $storeId,
        int $perPage = 50,
        ?array $statuses = null,
    ): LengthAwarePaginator {
        $query = PortalReturnRequest::query()
            ->with(['order.store', 'account.customer'])
            ->where('organization_id', $organizationId)
            ->whereHas('order', fn ($q) => $q->where('store_id', $storeId));

        if ($statuses !== null && $statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        return $query
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function openCountForStore(int $organizationId, int $storeId): int
    {
        return PortalReturnRequest::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', PortalReturnRequestStatuses::OPEN)
            ->whereHas('order', fn ($q) => $q->where('store_id', $storeId))
            ->count();
    }

    public function findForStore(int $organizationId, int $storeId, int $id): ?PortalReturnRequest
    {
        return PortalReturnRequest::query()
            ->with(['items.orderItem', 'order.store', 'account.customer'])
            ->where('organization_id', $organizationId)
            ->whereKey($id)
            ->whereHas('order', fn ($q) => $q->where('store_id', $storeId))
            ->first();
    }

    public function updateStatus(PortalReturnRequest $request, string $status): PortalReturnRequest
    {
        $current = (string) $request->status;

        if (! PortalReturnRequestStatuses::canStaffTransition($current, $status)) {
            throw ValidationException::withMessages([
                'status' => ['This status change is not allowed from '.$current.'.'],
            ]);
        }

        return DB::transaction(function () use ($request, $status): PortalReturnRequest {
            $locked = PortalReturnRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            $current = (string) $locked->status;
            if (! PortalReturnRequestStatuses::canStaffTransition($current, $status)) {
                throw ValidationException::withMessages([
                    'status' => ['This status change is not allowed from '.$current.'.'],
                ]);
            }

            $locked->status = $status;
            $locked->save();

            return $locked->load(['items.orderItem', 'order.store', 'account.customer']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toListItem(PortalReturnRequest $request): array
    {
        $request->loadMissing(['order', 'account.customer']);

        return [
            'id' => $request->id,
            'orderId' => $request->portal_order_id,
            'orderNumber' => $request->order?->order_number,
            'status' => $request->status,
            'statusLabel' => PortalReturnRequestStatuses::label((string) $request->status),
            'reason' => $request->reason,
            'reasonLabel' => \Modules\CustomerPortal\Support\PortalReturnReasons::label((string) $request->reason),
            'customerName' => $request->account?->customer?->name,
            'createdAt' => $request->created_at?->toIso8601String(),
            'actionable' => in_array((string) $request->status, PortalReturnRequestStatuses::STAFF_ACTIONABLE, true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(PortalReturnRequest $request): array
    {
        $payload = $this->returns->toPayload($request);
        $request->loadMissing(['order.store', 'account.customer']);

        $payload['customer'] = [
            'name' => $request->account?->customer?->name,
            'email' => $request->account?->email,
        ];
        $payload['store'] = $request->order?->store ? [
            'id' => $request->order->store->id,
            'name' => $request->order->store->name,
        ] : null;
        $payload['allowedStatuses'] = PortalReturnRequestStatuses::allowedStaffTransitions((string) $request->status);
        $payload['actionable'] = in_array((string) $request->status, PortalReturnRequestStatuses::STAFF_ACTIONABLE, true);
        $payload['refundTotal'] = $this->completions->refundTotalForRequest($request);
        $payload['canComplete'] = (string) $request->status === PortalReturnRequestStatuses::APPROVED;
        $payload['orderPaymentProvider'] = $request->order?->payment_provider;

        return $payload;
    }
}
