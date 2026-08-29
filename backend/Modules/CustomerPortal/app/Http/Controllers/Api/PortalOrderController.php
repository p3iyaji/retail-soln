<?php

namespace Modules\CustomerPortal\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Services\PortalCheckoutService;
use Modules\CustomerPortal\Services\PortalOrderPayloadBuilder;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PortalOrderController
{
    public function index(Request $request, PortalOrderPayloadBuilder $payloads): JsonResponse
    {
        /** @var CustomerAccount $account */
        $account = $request->user();

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = PortalOrder::query()
            ->with(['store'])
            ->where('customer_account_id', $account->id)
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(
                fn (PortalOrder $order) => $payloads->listItem($order),
            )->values(),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $order, PortalOrderPayloadBuilder $payloads): JsonResponse
    {
        /** @var CustomerAccount $account */
        $account = $request->user();

        $model = $this->ownedOrder($account, $order);

        return response()->json([
            'data' => $payloads->build($model, enriched: true),
        ]);
    }

    public function cancel(
        Request $request,
        int $order,
        PortalCheckoutService $checkout,
        PortalOrderPayloadBuilder $payloads,
    ): JsonResponse {
        /** @var CustomerAccount $account */
        $account = $request->user();

        $updated = $checkout->cancelAwaitingPayment($account, $order);

        return response()->json([
            'data' => $payloads->build($updated, enriched: true),
        ]);
    }

    public function invoice(Request $request, int $order, PortalOrderPayloadBuilder $payloads): Response
    {
        /** @var CustomerAccount $account */
        $account = $request->user();

        $model = $this->ownedOrder($account, $order);
        $model->loadMissing(['items', 'store', 'address', 'customer', 'organization']);

        if (! $payloads->invoiceAvailable($model)) {
            throw new HttpException(422, 'Invoice is available after payment is completed.');
        }

        $filename = 'invoice-'.$model->order_number.'.html';

        return response()->view('customerportal::invoice-document', [
            'order' => $model,
        ])->withHeaders([
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function ownedOrder(CustomerAccount $account, int $orderId): PortalOrder
    {
        return PortalOrder::query()
            ->with(['items', 'store', 'address', 'customer', 'organization'])
            ->where('customer_account_id', $account->id)
            ->where('id', $orderId)
            ->firstOrFail();
    }
}
