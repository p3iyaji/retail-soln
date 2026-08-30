<?php

namespace Modules\CustomerPortal\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Services\PortalFulfilmentService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use RuntimeException;

class StaffFulfilmentController
{
    public function index(
        Request $request,
        OrganizationService $organizations,
        PortalFulfilmentService $fulfilment,
    ): JsonResponse {
        $user = $request->user();
        $this->assertCanViewFulfilment($user);

        $storeId = $this->resolveStoreId($request, $user);
        $orgId = $organizations->current()->id;
        $this->assertStoreInOrg($orgId, $storeId);

        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);
        $paginator = $fulfilment->listForStore($orgId, $storeId, $perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn ($order) => $fulfilment->toListItem($order),
            )->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(
        Request $request,
        int $order,
        OrganizationService $organizations,
        PortalFulfilmentService $fulfilment,
    ): JsonResponse {
        $user = $request->user();
        $this->assertCanViewFulfilment($user);

        $storeId = $this->resolveStoreId($request, $user);
        $orgId = $organizations->current()->id;
        $this->assertStoreInOrg($orgId, $storeId);

        $model = $fulfilment->findForStore($orgId, $storeId, $order);
        if ($model === null) {
            abort(404);
        }

        return response()->json([
            'data' => $fulfilment->toDetail($model),
        ]);
    }

    public function pack(
        Request $request,
        int $order,
        OrganizationService $organizations,
        PortalFulfilmentService $fulfilment,
    ): JsonResponse {
        $user = $request->user();
        if ($user?->hasPermission('fulfilment.pick') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $storeId = $this->resolveStoreId($request, $user);
        $orgId = $organizations->current()->id;
        $this->assertStoreInOrg($orgId, $storeId);

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        $model = $fulfilment->findForStore($orgId, $storeId, $order);
        if ($model === null) {
            abort(404);
        }

        try {
            $result = $fulfilment->packComplete(
                $model,
                $storeId,
                (int) $user->id,
                $idempotencyKey,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $fulfilment->toDetail($result['order']->loadMissing(['store', 'items', 'customer', 'address', 'shipment'])),
        ]);
    }

    public function ship(
        Request $request,
        int $order,
        OrganizationService $organizations,
        PortalFulfilmentService $fulfilment,
    ): JsonResponse {
        $user = $request->user();
        if ($user?->hasPermission('fulfilment.ship') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $storeId = $this->resolveStoreId($request, $user);
        $orgId = $organizations->current()->id;
        $this->assertStoreInOrg($orgId, $storeId);

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        $validated = $request->validate([
            'carrierId' => [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('carriers', 'id')->where(
                    fn ($q) => $q->where('organization_id', $orgId)->where('is_active', true)
                ),
            ],
            'carrierName' => ['nullable', 'string', 'max:120'],
            'trackingNumber' => ['nullable', 'string', 'max:120'],
        ]);

        $model = $fulfilment->findForStore($orgId, $storeId, $order);
        if ($model === null) {
            abort(404);
        }

        try {
            $result = $fulfilment->ship(
                $model,
                $storeId,
                $idempotencyKey,
                [
                    'carrierId' => $validated['carrierId'] ?? null,
                    'carrierName' => $validated['carrierName'] ?? null,
                    'trackingNumber' => $validated['trackingNumber'] ?? null,
                ],
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $fulfilment->toDetail($result['order']->loadMissing(['store', 'items', 'customer', 'address', 'shipment'])),
        ]);
    }

    public function deliver(
        Request $request,
        int $order,
        OrganizationService $organizations,
        PortalFulfilmentService $fulfilment,
    ): JsonResponse {
        $user = $request->user();
        if ($user?->hasPermission('fulfilment.ship') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $storeId = $this->resolveStoreId($request, $user);
        $orgId = $organizations->current()->id;
        $this->assertStoreInOrg($orgId, $storeId);

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        $model = $fulfilment->findForStore($orgId, $storeId, $order);
        if ($model === null) {
            abort(404);
        }

        try {
            $result = $fulfilment->deliver($model, $storeId, $idempotencyKey);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $fulfilment->toDetail($result['order']->loadMissing(['store', 'items', 'customer', 'address', 'shipment'])),
        ]);
    }

    public function ready(
        Request $request,
        int $order,
        OrganizationService $organizations,
        PortalFulfilmentService $fulfilment,
    ): JsonResponse {
        $user = $request->user();
        if ($user?->hasPermission('fulfilment.pickup') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $storeId = $this->resolveStoreId($request, $user);
        $orgId = $organizations->current()->id;
        $this->assertStoreInOrg($orgId, $storeId);

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        $model = $fulfilment->findForStoreAction($orgId, $storeId, $order);
        if ($model === null) {
            abort(404);
        }

        try {
            $result = $fulfilment->markReadyForPickup($model, $storeId, $idempotencyKey);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $fulfilment->toDetail($result['order']->loadMissing(['store', 'items', 'customer', 'address', 'shipment'])),
        ]);
    }

    public function collect(
        Request $request,
        int $order,
        OrganizationService $organizations,
        PortalFulfilmentService $fulfilment,
    ): JsonResponse {
        $user = $request->user();
        if ($user?->hasPermission('fulfilment.pickup') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $storeId = $this->resolveStoreId($request, $user);
        $orgId = $organizations->current()->id;
        $this->assertStoreInOrg($orgId, $storeId);

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        $model = $fulfilment->findForStoreAction($orgId, $storeId, $order);
        if ($model === null) {
            abort(404);
        }

        try {
            $result = $fulfilment->markCollected($model, $storeId, $idempotencyKey);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $fulfilment->toDetail($result['order']->loadMissing(['store', 'items', 'customer', 'address', 'shipment'])),
        ]);
    }

    private function assertCanViewFulfilment(mixed $user): void
    {
        if ($user?->hasPermission('fulfilment.pick') !== true
            && $user?->hasPermission('fulfilment.ship') !== true
            && $user?->hasPermission('fulfilment.pickup') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function resolveStoreId(Request $request, mixed $user): int
    {
        $header = $request->header('X-Store-Id');
        if ($header === null || $header === '' || ! ctype_digit((string) $header)) {
            abort(422, 'X-Store-Id header is required.');
        }

        $storeId = (int) $header;
        if ($user === null || ! $user->canAccessStore($storeId)) {
            abort(403, 'This action is unauthorized.');
        }

        return $storeId;
    }

    private function assertStoreInOrg(int $organizationId, int $storeId): void
    {
        $exists = Store::query()
            ->where('organization_id', $organizationId)
            ->whereKey($storeId)
            ->exists();

        if (! $exists) {
            abort(422, 'Store not found.');
        }
    }
}
