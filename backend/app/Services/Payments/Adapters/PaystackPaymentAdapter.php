<?php

namespace App\Services\Payments\Adapters;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\PaymentIntentResult;
use App\Services\Payments\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Paystack adapter stub — placeholder authorization URL without live keys outside production.
 */
class PaystackPaymentAdapter implements PaymentGateway
{
    public function createIntent(array $input): PaymentIntentResult
    {
        $secret = (string) config('payments.paystack.secret_key', '');
        $reference = $input['reference'] !== ''
            ? $input['reference']
            : 'paystack_'.Str::lower(Str::random(16));

        $success = (string) ($input['successUrl'] ?? '');
        if ($success === '') {
            throw new RuntimeException('Paystack success URL is required.');
        }

        if ($secret === '') {
            if (app()->environment('production')) {
                throw new RuntimeException('Paystack is not configured.');
            }

            return new PaymentIntentResult(
                provider: 'paystack',
                reference: $reference,
                status: 'awaiting_payment',
                redirectUrl: $success,
                requiresClientAction: true,
                meta: ['mode' => 'placeholder'],
            );
        }

        return new PaymentIntentResult(
            provider: 'paystack',
            reference: $reference,
            status: 'awaiting_payment',
            redirectUrl: 'https://checkout.paystack.com/'.$reference.'?callback='.urlencode($success),
            requiresClientAction: true,
            meta: ['mode' => 'configured_stub'],
        );
    }

    public function verifyWebhook(Request $request): WebhookEvent
    {
        $secret = (string) config('payments.paystack.webhook_secret', config('payments.paystack.secret_key', ''));
        $signature = (string) $request->header('x-paystack-signature', '');

        if ($secret !== '') {
            $expected = hash_hmac('sha512', $request->getContent(), $secret);
            if ($signature === '' || ! hash_equals($expected, $signature)) {
                throw new RuntimeException('Invalid Paystack webhook signature.');
            }
        } elseif (app()->environment('production')) {
            throw new RuntimeException('Paystack webhook secret is not configured.');
        }

        $payload = $request->all();
        $event = (string) ($payload['event'] ?? '');
        $data = $payload['data'] ?? [];
        $eventId = (string) ($payload['id'] ?? $data['id'] ?? $request->header('X-Paystack-Event-Id', ''));
        $reference = (string) ($data['reference'] ?? $payload['reference'] ?? '');

        $status = match ($event) {
            'charge.success' => 'paid',
            'charge.failed' => 'failed',
            default => (string) ($data['status'] ?? $payload['status'] ?? 'awaiting_payment'),
        };

        if ($status === 'success') {
            $status = 'paid';
        }

        if ($eventId === '' || $reference === '') {
            throw new RuntimeException('Paystack webhook missing event id or payment reference.');
        }

        return new WebhookEvent(
            provider: 'paystack',
            eventId: (string) $eventId,
            reference: $reference,
            status: $status,
            payload: $payload,
        );
    }
}
