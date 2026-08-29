<?php

namespace App\Services\Payments\Contracts;

use App\Services\Payments\PaymentIntentResult;
use App\Services\Payments\WebhookEvent;
use Illuminate\Http\Request;

interface PaymentGateway
{
    /**
     * @param  array{
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
    public function createIntent(array $input): PaymentIntentResult;

    public function verifyWebhook(Request $request): WebhookEvent;
}
