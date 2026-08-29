<?php

namespace Tests\Feature;

use App\Models\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.stripe.webhook_secret' => self::SECRET,
            'payments.stripe.webhook_tolerance' => 300,
        ]);
    }

    public function test_valid_signature_is_accepted_and_recorded_once(): void
    {
        $payload = $this->payload('evt_valid_1');

        $this->postStripe($payload, $this->signature($payload))->assertOk();

        $this->assertSame(1, PaymentWebhookEvent::query()->where('event_id', 'evt_valid_1')->count());
    }

    public function test_missing_signature_is_rejected(): void
    {
        $payload = $this->payload('evt_missing');

        $this->postStripe($payload, null)->assertStatus(400);

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }

    public function test_forged_signature_is_rejected(): void
    {
        $payload = $this->payload('evt_forged');
        $forged = 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, 'wrong-secret');

        $this->postStripe($payload, $forged)->assertStatus(400);

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }

    /**
     * The pre-9.1 adapter accepted the raw webhook secret as the signature header,
     * which turned a leaked config value into a forgery oracle.
     */
    public function test_secret_as_signature_header_is_rejected(): void
    {
        $payload = $this->payload('evt_secret_as_sig');

        $this->postStripe($payload, self::SECRET)->assertStatus(400);

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }

    public function test_malformed_signature_header_is_rejected(): void
    {
        $payload = $this->payload('evt_malformed');

        $this->postStripe($payload, 't=not-a-timestamp,v1=deadbeef')->assertStatus(400);
        $this->postStripe($payload, 'v1='.hash_hmac('sha256', $payload, self::SECRET))->assertStatus(400);

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }

    public function test_replayed_signature_outside_tolerance_is_rejected(): void
    {
        $payload = $this->payload('evt_replay');
        $stale = time() - 3600;

        $this->postStripe($payload, $this->signature($payload, $stale))->assertStatus(400);

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }

    public function test_webhook_endpoint_is_throttled(): void
    {
        config(['security.rate_limits.payment_webhooks' => 2]);

        for ($i = 0; $i < 2; $i++) {
            $payload = $this->payload('evt_throttle_'.$i);
            $this->postStripe($payload, $this->signature($payload))->assertOk();
        }

        $payload = $this->payload('evt_throttle_blocked');
        $this->postStripe($payload, $this->signature($payload))->assertStatus(429);

        $this->assertSame(0, PaymentWebhookEvent::query()->where('event_id', 'evt_throttle_blocked')->count());
    }

    private function payload(string $eventId): string
    {
        return (string) json_encode([
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['client_reference_id' => 'portal_ref_'.$eventId]],
        ]);
    }

    private function signature(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);
    }

    private function postStripe(string $payload, ?string $signature): TestResponse
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($signature !== null) {
            $headers['Stripe-Signature'] = $signature;
        }

        return $this->call(
            'POST',
            '/api/v1/webhooks/payments/stripe',
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers),
            $payload,
        );
    }
}
