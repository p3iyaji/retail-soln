<?php

namespace App\Services\Payments;

final class PaymentRefundResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $reference,
        public readonly string $status,
        public readonly ?string $gatewayReference = null,
        /** @var array<string, mixed> */
        public readonly array $meta = [],
    ) {}
}
