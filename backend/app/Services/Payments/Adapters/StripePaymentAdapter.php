<?php

namespace App\Services\Payments\Adapters;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\PaymentIntentResult;
use App\Services\Payments\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stripe adapter stub — placeholder checkout URL without live keys outside production.
 */
class StripePaymentAdapter implements PaymentGateway
{
    public function createIntent(array $input): PaymentIntentResult
    {
        $secret = (string) config('payments.stripe.secret_key', '');
        $reference = $input['reference'] !== ''
            ? $input['reference']
            : 'stripe_'.Str::lower(Str::random(16));

        $success = (string) ($input['successUrl'] ?? '');
        if ($success === '') {
            throw new RuntimeException('Stripe success URL is required.');
        }

        if ($secret === '') {
            if (app()->environment('production')) {
                throw new RuntimeException('Stripe is not configured.');
            }

            return new PaymentIntentResult(
                provider: 'stripe',
                reference: $reference,
                status: 'awaiting_payment',
                redirectUrl: $success,
                requiresClientAction: true,
                meta: ['mode' => 'placeholder'],
            );
        }

        return new PaymentIntentResult(
            provider: 'stripe',
            reference: $reference,
            status: 'awaiting_payment',
            redirectUrl: $success.(str_contains($success, '?') ? '&' : '?').'session_id='.urlencode($reference),
            requiresClientAction: true,
            meta: ['mode' => 'configured_stub'],
        );
    }

    public function verifyWebhook(Request $request): WebhookEvent
    {
        $secret = (string) config('payments.stripe.webhook_secret', '');
        $signature = (string) $request->header('Stripe-Signature', '');

        if ($secret !== '') {
            $payload = $request->getContent();
            if (! $this->validStripeSignature($payload, $signature, $secret)) {
                throw new RuntimeException('Invalid Stripe webhook signature.');
            }
        } elseif (app()->environment('production')) {
            throw new RuntimeException('Stripe webhook secret is not configured.');
        }

        $payload = $request->all();
        $eventId = (string) ($payload['id'] ?? $request->header('X-Stripe-Event-Id', ''));
        $dataObject = $payload['data']['object'] ?? [];
        $reference = (string) ($dataObject['client_reference_id']
            ?? $dataObject['metadata']['reference']
            ?? $payload['reference']
            ?? '');
        $type = (string) ($payload['type'] ?? '');
        $status = match ($type) {
            'checkout.session.completed', 'payment_intent.succeeded' => 'paid',
            'payment_intent.payment_failed', 'checkout.session.expired' => 'failed',
            default => (string) ($payload['status'] ?? 'awaiting_payment'),
        };

        if ($eventId === '' || $reference === '') {
            throw new RuntimeException('Stripe webhook missing event id or payment reference.');
        }

        return new WebhookEvent(
            provider: 'stripe',
            eventId: $eventId,
            reference: $reference,
            status: $status,
            payload: $payload,
        );
    }

    /**
     * Stripe `Stripe-Signature` scheme: `t=<unix>,v1=<hmac-sha256 of "t.payload">`.
     * A malformed header is never accepted, and stale timestamps are rejected so a
     * captured-but-valid webhook cannot be replayed indefinitely.
     */
    private function validStripeSignature(string $payload, string $header, string $secret): bool
    {
        if ($header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            }
            if (str_starts_with($part, 'v1=')) {
                $signatures[] = substr($part, 3);
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        $tolerance = max(0, (int) config('payments.stripe.webhook_tolerance', 300));
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
