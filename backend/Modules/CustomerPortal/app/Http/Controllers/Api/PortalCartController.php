<?php

namespace Modules\CustomerPortal\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Services\PortalCartService;

class PortalCartController
{
    public function show(Request $request, PortalCartService $carts): JsonResponse
    {
        /** @var CustomerAccount $account */
        $account = $request->user();
        $storeId = $request->integer('storeId') ?: null;

        return response()->json([
            'data' => $carts->getCart($account, $storeId ?: null),
        ]);
    }

    public function add(Request $request, PortalCartService $carts): JsonResponse
    {
        $validated = $request->validate([
            'variantId' => ['required', 'integer', 'min:1'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'storeId' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var CustomerAccount $account */
        $account = $request->user();

        $payload = $carts->addItem(
            $account,
            (int) $validated['variantId'],
            $this->qtyString($validated['qty']),
            isset($validated['storeId']) ? (int) $validated['storeId'] : null,
        );

        return response()->json(['data' => $payload], 201);
    }

    public function update(Request $request, int $item, PortalCartService $carts): JsonResponse
    {
        $validated = $request->validate([
            'qty' => ['required', 'numeric', 'min:0'],
            'storeId' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var CustomerAccount $account */
        $account = $request->user();

        $payload = $carts->updateItem(
            $account,
            $item,
            $this->qtyString($validated['qty']),
            isset($validated['storeId']) ? (int) $validated['storeId'] : null,
        );

        return response()->json(['data' => $payload]);
    }

    public function destroy(Request $request, int $item, PortalCartService $carts): JsonResponse
    {
        /** @var CustomerAccount $account */
        $account = $request->user();
        $storeId = $request->integer('storeId') ?: null;

        $payload = $carts->removeItem($account, $item, $storeId ?: null);

        return response()->json(['data' => $payload]);
    }

    private function qtyString(mixed $qty): string
    {
        return bcadd((string) $qty, '0', 4);
    }
}
