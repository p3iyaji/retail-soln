<?php

namespace Modules\CustomerPortal\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\CustomerPortal\Services\PortalReturnCompletionService;
use Modules\CustomerPortal\Services\PortalReturnRequestStaffService;
use Modules\CustomerPortal\Support\PortalReturnRequestStatuses;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Enums\PaymentMethod;

class StaffReturnRequestController
{
    public function index(
        Request $request,
        OrganizationService $organizations,
        PortalReturnRequestStaffService $returns,
    ): JsonResponse {
        $user = $request->user();
        $this->assertCanView($user);

        $storeId = $this->resolveStoreId($request, $user);
        $orgId = $organizations->current()->id;
        $this->assertStoreInOrg($orgId, $storeId);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(PortalReturnRequestStatuses::ALL)],
        ]);

        $statuses = null;
        if ($request->boolean('openOnly')) {
            $statuses = PortalReturnRequestStatuses::OPEN;
        } elseif (isset($validated['status'])) {
            $statuses = [(string) $validated['status']];
        }

        $perPage = min(max((int) ($validated['per_page'] ?? 50), 1), 100);
        $paginator = $returns->listForStore($orgId, $storeId, $perPage, $statuses);

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn ($row) => $returns->toListItem($row),
            )->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'openCount' => $returns->openCountForStore($orgId, $storeId),
            ],
        ]);
    }

    public function show(
        Request $request,
        int $returnRequest,
        OrganizationService $organizations,
        PortalReturnRequestStaffService $returns,
    ): JsonResponse {
        $user = $request->user();
        $this->assertCanView($user);

        $storeId = $this->resolveStoreId($request, $user);
        $orgId = $organizations->current()->id;
        $this->assertStoreInOrg($orgId, $storeId);

        $model = $returns->findForStore($orgId, $storeId, $returnRequest);
        if ($model === null) {
            abort(404);
        }

        return response()->json([
            'data' => $returns->toPayload($model),
        ]);
    }

    public function update(
        Request $request,
        int $returnRequest,
        OrganizationService $organizations,
        PortalReturnRequestStaffService $returns,
    ): JsonResponse {
        $user = $request->user();
        if ($user?->hasPermission('fulfilment.pick') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $storeId = $this->resolveStoreId($request, $user);
        $orgId = $organizations->current()->id;
        $this->assertStoreInOrg($orgId, $storeId);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(PortalReturnRequestStatuses::ALL)],
        ]);

        $model = $returns->findForStore($orgId, $storeId, $returnRequest);
        if ($model === null) {
            abort(404);
        }

        $updated = $returns->updateStatus($model, (string) $validated['status']);

        return response()->json([
            'data' => $returns->toPayload($updated),
        ]);
    }

    public function complete(
        Request $request,
        int $returnRequest,
        OrganizationService $organizations,
        PortalReturnRequestStaffService $returns,
        PortalReturnCompletionService $completions,
    ): JsonResponse {
        $user = $request->user();
        if ($user?->hasPermission('fulfilment.pick') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $storeId = $this->resolveStoreId($request, $user);
        $orgId = $organizations->current()->id;
        $this->assertStoreInOrg($orgId, $storeId);

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        $validated = $request->validate([
            'refunds' => ['required', 'array', 'min:1'],
            'refunds.*.provider' => ['required', 'string', Rule::in(PortalReturnCompletionService::REFUND_PROVIDERS)],
            'refunds.*.method' => ['nullable', 'string', Rule::in(PaymentMethod::values())],
            'refunds.*.amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gt:0'],
            'refunds.*.reference' => ['nullable', 'string', 'max:128'],
        ]);

        $model = $returns->findForStore($orgId, $storeId, $returnRequest);
        if ($model === null) {
            abort(404);
        }

        $result = $completions->complete(
            $model,
            $storeId,
            (int) $user->id,
            trim($idempotencyKey),
            collect($validated['refunds'])->map(fn (array $refund) => [
                'provider' => strtolower((string) $refund['provider']),
                'method' => $refund['method'] ?? null,
                'amount' => bcadd((string) $refund['amount'], '0', 4),
                'reference' => $refund['reference'] ?? null,
            ])->all(),
        );

        return response()->json([
            'data' => $returns->toPayload($result['request']),
        ], $result['created'] ? 201 : 200);
    }

    private function assertCanView(mixed $user): void
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
