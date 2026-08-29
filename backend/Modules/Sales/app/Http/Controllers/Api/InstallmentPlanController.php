<?php

namespace Modules\Sales\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Enums\InstallmentPlanStatus;
use Modules\Sales\Enums\InstallmentStatus;
use Modules\Sales\Http\Concerns\ResolvesPosStore;
use Modules\Sales\Http\Requests\CollectInstallmentPaymentRequest;
use Modules\Sales\Http\Resources\InstallmentPaymentResource;
use Modules\Sales\Http\Resources\InstallmentPlanResource;
use Modules\Sales\Models\InstallmentPlan;
use Modules\Sales\Services\IdempotencyConflictException;
use Modules\Sales\Services\InstallmentCollectionService;
use Modules\Sales\Services\InstallmentPlanCancellationService;
use RuntimeException;

class InstallmentPlanController
{
    use ResolvesPosStore;

    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        if ($request->user()?->hasPermission('sales.installments.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $organizationId = $organizations->current()->id;
        $storeId = $this->resolvePosStoreId($request);

        $query = InstallmentPlan::query()
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->with(['customer', 'installments'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        } else {
            $query->where('status', InstallmentPlanStatus::Open);
        }

        if ($request->filled('customerId')) {
            $query->where('customer_id', (int) $request->integer('customerId'));
        }

        if ($request->boolean('overdueOnly')) {
            $query->whereHas('installments', function ($q): void {
                $q->where('status', InstallmentStatus::Pending)
                    ->whereDate('due_at', '<', now()->toDateString())
                    ->whereColumn('paid_amount', '<', 'amount');
            });
        }

        if ($search = $request->string('q')->trim()->toString()) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search));
            $like = '%'.$escaped.'%';
            $query->where(function ($q) use ($like, $search): void {
                if (is_numeric($search)) {
                    $q->where('id', (int) $search)
                        ->orWhere('sale_id', (int) $search);
                }
                $q->orWhereHas('customer', function ($cq) use ($like): void {
                    $cq->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereRaw("LOWER(account_number) LIKE ? ESCAPE '\\'", [$like]);
                });
            });
        }

        $plans = $query->limit(50)->get();

        return response()->json([
            'data' => InstallmentPlanResource::collection($plans),
        ]);
    }

    public function show(
        Request $request,
        InstallmentPlan $installmentPlan,
        OrganizationService $organizations,
    ): JsonResponse {
        if ($request->user()?->hasPermission('sales.installments.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        if ($installmentPlan->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        $storeId = $this->resolvePosStoreId($request);
        if ($installmentPlan->store_id !== $storeId) {
            abort(404);
        }

        $installmentPlan->load(['customer', 'installments']);

        return response()->json([
            'data' => new InstallmentPlanResource($installmentPlan),
        ]);
    }

    public function collectPayment(
        CollectInstallmentPaymentRequest $request,
        InstallmentPlan $installmentPlan,
        OrganizationService $organizations,
        InstallmentCollectionService $service,
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        if ($installmentPlan->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        $storeId = $request->storeId();
        if ($installmentPlan->store_id !== $storeId) {
            abort(404);
        }

        $attrs = $request->collectionAttributes();

        try {
            $result = $service->collect([
                'organizationId' => $organizations->current()->id,
                'storeId' => $storeId,
                'userId' => $request->user()->id,
                'planId' => $installmentPlan->id,
                'installmentId' => $attrs['installmentId'],
                'sessionId' => $attrs['sessionId'],
                'idempotencyKey' => $idempotencyKey,
                'allowPartial' => $request->user()->hasPermission('sales.installments.partial') === true,
                'payment' => $attrs['payment'],
            ]);
        } catch (IdempotencyConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new InstallmentPaymentResource($result['payment']),
        ], $result['created'] ? 201 : 200);
    }

    public function cancel(
        Request $request,
        InstallmentPlan $installmentPlan,
        OrganizationService $organizations,
        InstallmentPlanCancellationService $service,
    ): JsonResponse {
        if ($request->user()?->hasPermission('sales.installments.cancel') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        if ($installmentPlan->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        $storeId = $this->resolvePosStoreId($request);
        if ($installmentPlan->store_id !== $storeId) {
            abort(404);
        }

        try {
            $plan = $service->cancel($installmentPlan);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new InstallmentPlanResource($plan),
        ]);
    }
}
