<?php

namespace App\Services\Payments;

use App\Models\PaymentWebhookEvent;
use App\Services\Payments\Adapters\ManualPaymentAdapter;
use App\Services\Payments\Adapters\PaystackPaymentAdapter;
use App\Services\Payments\Adapters\StripePaymentAdapter;
use App\Services\Payments\Contracts\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentService
{
    /**
     * @var array<string, PaymentGateway>
     */
    private array $gateways;

    public function __construct(
        ?ManualPaymentAdapter $manual = null,
        ?StripePaymentAdapter $stripe = null,
        ?PaystackPaymentAdapter $paystack = null,
    ) {
        $this->gateways = [
            'manual' => $manual ?? new ManualPaymentAdapter,
            'stripe' => $stripe ?? new StripePaymentAdapter,
            'paystack' => $paystack ?? new PaystackPaymentAdapter,
        ];
    }

    public function gateway(string $provider): PaymentGateway
    {
        $key = strtolower($provider);
        if (! isset($this->gateways[$key])) {
            throw new InvalidArgumentException('Unsupported payment provider: '.$provider);
        }

        return $this->gateways[$key];
    }

    /**
     * @param  array{
     *   provider: string,
     *   reference: string,
     *   amount: string,
     *   currency?: string,
     *   email?: ?string,
     *   description?: ?string,
     *   metadata?: array<string, mixed>,
     *   successUrl?: ?string,
     *   cancelUrl?: ?string,
     * }  $input
     */
    public function createIntent(array $input): PaymentIntentResult
    {
        $provider = strtolower($input['provider']);

        try {
            return $this->gateway($provider)->createIntent($input);
        } catch (RuntimeException $e) {
            if (app()->environment('production') && str_contains(strtolower($e->getMessage()), 'not configured')) {
                throw new HttpException(503, $e->getMessage(), $e);
            }

            throw $e;
        }
    }

    /**
     * Verify signature, persist webhook idempotently, and return whether this event is new.
     *
     * @return array{event: WebhookEvent, created: bool}
     */
    public function handleWebhook(string $provider, Request $request): array
    {
        $event = $this->gateway($provider)->verifyWebhook($request);

        return DB::transaction(function () use ($event) {
            $existing = PaymentWebhookEvent::query()
                ->where('provider', $event->provider)
                ->where('event_id', $event->eventId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [
                    'event' => new WebhookEvent(
                        provider: $existing->provider,
                        eventId: $existing->event_id,
                        reference: (string) $existing->payment_reference,
                        status: (string) $existing->status,
                        payload: $existing->payload ?? [],
                    ),
                    'created' => false,
                ];
            }

            PaymentWebhookEvent::query()->create([
                'provider' => $event->provider,
                'event_id' => $event->eventId,
                'payment_reference' => $event->reference,
                'status' => $event->status,
                'payload' => $event->payload,
                'processed_at' => now(),
            ]);

            return [
                'event' => $event,
                'created' => true,
            ];
        });
    }
}
