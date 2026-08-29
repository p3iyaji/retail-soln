<?php

namespace Modules\Sales\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Http\Concerns\ResolvesPosStore;
use Modules\Sales\Http\Resources\ReceiptResource;
use Modules\Sales\Jobs\SendSaleReceipt;
use Modules\Sales\Models\Sale;

class PosReceiptController
{
    use ResolvesPosStore;

    public function show(
        Request $request,
        Sale $sale,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertView($request);
        $this->assertOrg($sale, $organizations);
        $this->assertStore($request, $sale);

        $sale->load(['items.productVariant', 'payments', 'store', 'customer', 'invoice']);

        return response()->json([
            'data' => new ReceiptResource($sale),
        ]);
    }

    public function print(
        Request $request,
        Sale $sale,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertView($request);
        $this->assertOrg($sale, $organizations);
        $this->assertStore($request, $sale);

        SendSaleReceipt::dispatch($sale->id);

        $sale->load(['items.productVariant', 'payments', 'store', 'customer', 'invoice']);

        return response()->json([
            'data' => new ReceiptResource($sale),
            'meta' => [
                'queued' => true,
                'message' => 'Receipt print job queued.',
            ],
        ]);
    }

    private function assertView(Request $request): void
    {
        if ($request->user()?->hasPermission('sales.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertOrg(Sale $sale, OrganizationService $organizations): void
    {
        if ($sale->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }

    private function assertStore(Request $request, Sale $sale): void
    {
        $storeId = $this->resolvePosStoreId($request);
        if ($sale->store_id !== $storeId) {
            abort(404);
        }
    }
}
