<?php

namespace Modules\Sales\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Http\Requests\CloseCashierSessionRequest;
use Modules\Sales\Http\Requests\CurrentCashierSessionRequest;
use Modules\Sales\Http\Requests\OpenCashierSessionRequest;
use Modules\Sales\Http\Requests\StoreCashMovementRequest;
use Modules\Sales\Http\Resources\CashierSessionResource;
use Modules\Sales\Http\Resources\CashMovementResource;
use Modules\Sales\Models\CashierSession;
use Modules\Sales\Services\CashierSessionService;
use RuntimeException;

class PosCashierSessionController
{
    public function open(
        OpenCashierSessionRequest $request,
        OrganizationService $organizations,
        CashierSessionService $service,
    ): JsonResponse {
        try {
            $session = $service->open(
                organizationId: $organizations->current()->id,
                storeId: $request->storeId(),
                userId: $request->user()->id,
                terminalName: $request->terminalName(),
                openingFloat: $request->openingFloat(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new CashierSessionResource($session),
        ], 201);
    }

    public function current(
        CurrentCashierSessionRequest $request,
        OrganizationService $organizations,
        CashierSessionService $service,
    ): JsonResponse {
        $session = $service->findOpenForTerminal(
            organizationId: $organizations->current()->id,
            storeId: $request->storeId(),
            terminalName: $request->terminalName(),
        );

        if ($session === null) {
            return response()->json(['data' => null]);
        }

        $session->load(['cashMovements']);

        return response()->json([
            'data' => new CashierSessionResource($session),
        ]);
    }

    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        if (
            $request->user()?->hasPermission('registers.reconcile') !== true
            && $request->user()?->hasPermission('pos.session') !== true
        ) {
            abort(403);
        }

        $header = $request->header('X-Store-Id');
        if ($header === null || $header === '' || ! ctype_digit((string) $header)) {
            abort(422, 'X-Store-Id header is required.');
        }

        $storeId = (int) $header;
        if (! $request->user()->canAccessStore($storeId)) {
            abort(403);
        }

        $organizationId = $organizations->current()->id;

        $sessions = CashierSession::query()
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->with(['terminal', 'cashier', 'store'])
            ->orderByDesc('opened_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => CashierSessionResource::collection($sessions),
        ]);
    }

    public function show(
        CashierSession $session,
        Request $request,
        OrganizationService $organizations,
    ): JsonResponse {
        if (
            $request->user()?->hasPermission('registers.reconcile') !== true
            && $request->user()?->hasPermission('pos.session') !== true
        ) {
            abort(403);
        }

        if ($session->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        if (! $request->user()->canAccessStore($session->store_id)) {
            abort(403);
        }

        $session->load(['terminal', 'cashier', 'store', 'cashMovements']);

        return response()->json([
            'data' => new CashierSessionResource($session),
        ]);
    }

    public function close(
        CashierSession $session,
        CloseCashierSessionRequest $request,
        OrganizationService $organizations,
        CashierSessionService $service,
    ): JsonResponse {
        if ($session->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        if ($session->store_id !== $request->storeId()) {
            abort(404);
        }

        try {
            $closed = $service->close(
                session: $session,
                userId: $request->user()->id,
                closingCash: $request->closingCash(),
                notes: $request->notes(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new CashierSessionResource($closed),
        ]);
    }

    public function storeMovement(
        CashierSession $session,
        StoreCashMovementRequest $request,
        OrganizationService $organizations,
        CashierSessionService $service,
    ): JsonResponse {
        if ($session->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        if ($session->store_id !== $request->storeId()) {
            abort(404);
        }

        try {
            $movement = $service->recordCashMovement(
                session: $session,
                userId: $request->user()->id,
                type: $request->movementType(),
                amount: $request->amount(),
                notes: $request->notes(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new CashMovementResource($movement),
        ], 201);
    }
}
