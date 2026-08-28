<?php

namespace Modules\Sales\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Http\Resources\ReceiptResource;
use Modules\Sales\Jobs\SendSaleReceipt;
use Modules\Sales\Models\Sale;

class PosReceiptController
{
    public function show(
        Request $request,
        Sale $sale,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertView($request);
        $this->assertOrg($sale, $organizations);

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
}
