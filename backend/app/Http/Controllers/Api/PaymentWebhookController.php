<?php

namespace App\Http\Controllers\Api;

use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CustomerPortal\Services\PortalCheckoutService;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentWebhookController
{
    public function stripe(
        Request $request,
        PaymentService $payments,
        PortalCheckoutService $checkout,
    ): JsonResponse {
        return $this->handle('stripe', $request, $payments, $checkout);
    }

    public function paystack(
        Request $request,
        PaymentService $payments,
        PortalCheckoutService $checkout,
    ): JsonResponse {
        return $this->handle('paystack', $request, $payments, $checkout);
    }

    public function manual(
        Request $request,
        PaymentService $payments,
        PortalCheckoutService $checkout,
    ): JsonResponse {
        return $this->handle('manual', $request, $payments, $checkout);
    }

    private function handle(
        string $provider,
        Request $request,
        PaymentService $payments,
        PortalCheckoutService $checkout,
    ): JsonResponse {
        try {
            $result = $payments->handleWebhook($provider, $request);
        } catch (RuntimeException $e) {
            throw new HttpException(400, $e->getMessage(), $e);
        }

        $event = $result['event'];

        try {
            $order = $checkout->applyPaymentReferenceStatus(
                $event->reference,
                $event->status,
                $provider,
            );
        } catch (HttpException $e) {
            throw $e;
        }

        return response()->json([
            'ok' => true,
            'created' => $result['created'],
            'orderId' => $order?->id,
            'paymentStatus' => $order?->payment_status ?? $event->status,
        ]);
    }
}
