<?php

namespace App\Services\Payments\Adapters;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\PaymentIntentResult;
use App\Services\Payments\PaymentRefundResult;
use App\Services\Payments\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class ManualPaymentAdapter implements PaymentGateway
{
    public function createIntent(array $input): PaymentIntentResult
    {
        if (! (bool) config('payments.manual.enabled', true)) {
            throw new RuntimeException('Manual payment provider is disabled.');
        }

        $reference = $input['reference'] !== ''
            ? $input['reference']
            : 'manual_'.Str::lower(Str::random(16));

        $orderId = $input['metadata']['orderId'] ?? null;
        $confirmPath = is_numeric($orderId)
            ? '/portal/orders/'.(int) $orderId.'/confirm-payment'
            : '/portal/orders/{id}/confirm-payment';

        return new PaymentIntentResult(
            provider: 'manual',
            reference: $reference,
            status: 'awaiting_payment',
            redirectUrl: null,
            requiresClientAction: false,
            meta: [
                'confirmPath' => $confirmPath,
                'confirmMethod' => 'POST',
            ],
        );
    }

    public function refund(array $input): PaymentRefundResult
    {
        if (! (bool) config('payments.manual.enabled', true)) {
            throw new RuntimeException('Manual payment provider is disabled.');
        }

        $reference = 'manual_refund_'.Str::lower(Str::random(16));

        return new PaymentRefundResult(
            provider: 'manual',
            reference: $reference,
            status: 'completed',
            gatewayReference: $reference,
        );
    }

    public function verifyWebhook(Request $request): WebhookEvent
    {
        $configured = (string) config('payments.manual.webhook_secret', '');
        if ($configured === '') {
            throw new RuntimeException('Manual webhook secret is not configured.');
        }

        $provided = (string) (
            $request->header('X-Manual-Webhook-Secret')
            ?? $request->header('X-Webhook-Secret')
            ?? ''
        );

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            throw new RuntimeException('Invalid or missing manual webhook secret.');
        }

        $eventId = (string) $request->input('event_id', $request->header('X-Manual-Event-Id', ''));
        $reference = (string) $request->input('reference', '');
        $status = (string) $request->input('status', 'paid');

        if ($eventId === '' || $reference === '') {
            throw new RuntimeException('Manual webhook requires event_id and reference.');
        }

        return new WebhookEvent(
            provider: 'manual',
            eventId: $eventId,
            reference: $reference,
            status: $status,
            payload: $request->all(),
        );
    }
}
