<?php

namespace Modules\CustomerPortal\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalReturnRequest;
use Modules\CustomerPortal\Services\PortalReturnRequestService;
use Modules\CustomerPortal\Support\PortalReturnReasons;

class PortalReturnRequestController
{
    public function index(Request $request): JsonResponse
    {
        /** @var CustomerAccount $account */
        $account = $request->user();

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = PortalReturnRequest::query()
            ->with('order')
            ->where('customer_account_id', $account->id)
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        $service = app(PortalReturnRequestService::class);

        return response()->json([
            'data' => collect($page->items())->map(
                fn (PortalReturnRequest $row) => $service->toListItem($row),
            )->values(),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $returnRequest, PortalReturnRequestService $service): JsonResponse
    {
        /** @var CustomerAccount $account */
        $account = $request->user();

        $model = $service->findOwned($account, $returnRequest);

        return response()->json([
            'data' => $service->toPayload($model),
        ]);
    }

    public function store(Request $request, PortalReturnRequestService $service): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }
        $idempotencyKey = trim($idempotencyKey);
        if (strlen($idempotencyKey) > 64) {
            return response()->json(['message' => 'Idempotency-Key must be at most 64 characters.'], 422);
        }

        $validated = $request->validate([
            'orderId' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', Rule::in(PortalReturnReasons::ALL)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.orderItemId' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'decimal:0,4', 'gt:0'],
        ]);

        /** @var CustomerAccount $account */
        $account = $request->user();

        $result = $service->create($account, $validated, $idempotencyKey);

        return response()->json([
            'data' => $service->toPayload($result['request']),
        ], $result['created'] ? 201 : 200);
    }
}
