<?php

namespace Tests\Concerns;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Money-moving purchasing endpoints reject requests without an `Idempotency-Key`
 * (Story 9.1). These helpers supply a fresh key unless the test provides its own.
 */
trait PostsIdempotentPurchasing
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function postGoodsReceipt(array $payload, array $headers = []): TestResponse
    {
        return $this->postIdempotent('/api/v1/goods-receipts', $payload, $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function postPurchaseReturn(array $payload, array $headers = []): TestResponse
    {
        return $this->postIdempotent('/api/v1/purchase-returns', $payload, $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function postSupplierInvoice(array $payload, array $headers = []): TestResponse
    {
        return $this->postIdempotent('/api/v1/supplier-invoices', $payload, $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function postSupplierPayment(array $payload, array $headers = []): TestResponse
    {
        return $this->postIdempotent('/api/v1/supplier-payments', $payload, $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function postIdempotent(string $uri, array $payload, array $headers): TestResponse
    {
        return $this->postJson($uri, $payload, $headers + ['Idempotency-Key' => (string) Str::uuid()]);
    }
}
