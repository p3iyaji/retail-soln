<?php

namespace App\Services\Payments;

final class PaymentIntentResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $reference,
        public readonly string $status,
        public readonly ?string $redirectUrl = null,
        public readonly bool $requiresClientAction = false,
        public readonly array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'reference' => $this->reference,
            'status' => $this->status,
            'redirectUrl' => $this->redirectUrl,
            'requiresClientAction' => $this->requiresClientAction,
            'meta' => $this->meta,
        ];
    }
}
