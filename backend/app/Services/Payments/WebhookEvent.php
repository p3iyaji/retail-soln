<?php

namespace App\Services\Payments;

final class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $eventId,
        public readonly string $reference,
        public readonly string $status,
        public readonly array $payload = [],
    ) {}
}
