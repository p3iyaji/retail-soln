<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Payments\PaymentService;
use Modules\CustomerPortal\Services\PortalCheckoutService;
use Modules\CustomerPortal\Services\PortalReturnCompletionService;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentWebhookController
{
    public function stripe(
        Request $request,
        PaymentService $payments,
        PortalCheckoutService $checkout,
        PortalReturnCompletionService $returns,
    ): JsonResponse {
        return $this->handle('stripe', $request, $payments, $checkout, $returns);
    }

    public function paystack(
        Request $request,
        PaymentService $payments,
        PortalCheckoutService $checkout,
        PortalReturnCompletionService $returns,
    ): JsonResponse {
        return $this->handle('paystack', $request, $payments, $checkout, $returns);
    }

    public function manual(
        Request $request,
        PaymentService $payments,
        PortalCheckoutService $checkout,
        PortalReturnCompletionService $returns,
    ): JsonResponse {
        return $this->handle('manual', $request, $payments, $checkout, $returns);
    }

    private function handle(
        string $provider,
        Request $request,
        PaymentService $payments,
        PortalCheckoutService $checkout,
        PortalReturnCompletionService $returns,
    ): JsonResponse {
        try {
            $result = $payments->handleWebhook($provider, $request);
        } catch (RuntimeException $e) {
            Log::warning('payments.webhook_failed', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
            ]);
            throw new HttpException(400, $e->getMessage(), $e);
        }

        $event = $result['event'];

        $refund = $returns->applyRefundWebhook($event);
        if ($refund !== null) {
            return response()->json([
                'ok' => true,
                'created' => $result['created'],
                'refundId' => $refund->id,
                'refundStatus' => $refund->status,
            ]);
        }

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
