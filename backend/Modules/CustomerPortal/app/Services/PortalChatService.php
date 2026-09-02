<?php

namespace Modules\CustomerPortal\Services;

use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\Products\Models\Product;

class PortalChatService
{
    public function __construct(
        private readonly PortalOrganizationResolver $organizations,
        private readonly PortalCatalogueService $catalogue,
        private readonly PortalStoreResolver $stores,
        private readonly PortalSupportInfoService $supportInfo,
    ) {}

    /**
     * @return array{
     *   reply: string,
     *   suggestions: list<string>,
     *   productMatches: list<array<string, mixed>>
     * }
     */
    public function respond(string $message, ?CustomerAccount $account = null): array
    {
        $normalized = mb_strtolower(trim($message));

        if ($normalized === '') {
            return $this->greeting($account);
        }

        if ($this->matchesAny($normalized, ['hello', 'hi', 'hey', 'good morning', 'good afternoon'])) {
            return $this->greeting($account);
        }

        if ($this->matchesAny($normalized, ['contact', 'human', 'agent', 'speak to', 'call me', 'email me'])) {
            return $this->contactEscalation();
        }

        if ($this->matchesAny($normalized, ['order', 'track', 'delivery status', 'where is my'])) {
            return $this->orderHelp($account);
        }

        if ($this->matchesAny($normalized, ['return', 'refund', 'exchange'])) {
            return $this->returnsHelp();
        }

        if ($this->matchesAny($normalized, ['ship', 'deliver', 'pickup', 'collect', 'fulfil'])) {
            return $this->shippingHelp();
        }

        if ($this->matchesAny($normalized, ['pay', 'payment', 'card', 'stripe', 'paystack'])) {
            return $this->paymentHelp();
        }

        if ($this->matchesAny($normalized, ['account', 'login', 'register', 'verify', 'password'])) {
            return $this->accountHelp();
        }

        if ($this->matchesAny($normalized, ['hour', 'open', 'location', 'store', 'address'])) {
            return $this->storeInfoHelp();
        }

        $searchTerm = $this->extractSearchTerm($normalized);
        if ($searchTerm !== null) {
            return $this->productSearch($searchTerm);
        }

        if (strlen($normalized) >= 3) {
            $productResults = $this->productSearch($normalized);
            if ($productResults['productMatches'] !== []) {
                return $productResults;
            }
        }

        return $this->fallback();
    }

    /**
     * @return array{reply: string, suggestions: list<string>, productMatches: list<array<string, mixed>>}
     */
    private function greeting(?CustomerAccount $account): array
    {
        $name = $account?->customer?->name;
        $prefix = $name ? "Hi {$name}! " : 'Hi! ';

        return [
            'reply' => $prefix.'I can help with orders, returns, delivery options, payments, and finding products. What would you like to know?',
            'suggestions' => [
                'Track my order',
                'Returns policy',
                'Delivery options',
                'Search products',
            ],
            'productMatches' => [],
        ];
    }

    /**
     * @return array{reply: string, suggestions: list<string>, productMatches: list<array<string, mixed>>}
     */
    private function contactEscalation(): array
    {
        $info = $this->supportInfo->build();
        $lines = ['I can connect you with our team. Visit the Contact page to send a message.'];

        if ($info['supportEmail']) {
            $lines[] = 'Email: '.$info['supportEmail'];
        }
        if ($info['supportPhone']) {
            $lines[] = 'Phone: '.$info['supportPhone'];
        }

        return [
            'reply' => implode("\n", $lines),
            'suggestions' => ['Open contact page', 'Store locations', 'Business hours'],
            'productMatches' => [],
        ];
    }

    /**
     * @return array{reply: string, suggestions: list<string>, productMatches: list<array<string, mixed>>}
     */
    private function orderHelp(?CustomerAccount $account): array
    {
        if ($account === null) {
            return [
                'reply' => 'Sign in to view your orders and track fulfilment status. After checkout you can also find order details under Orders in the shop header.',
                'suggestions' => ['Sign in', 'Delivery options', 'Contact support'],
                'productMatches' => [],
            ];
        }

        return [
            'reply' => 'Open Orders from the shop header to see status, invoices, and return options for each purchase. Paid and delivered orders may be eligible for a return request.',
            'suggestions' => ['View my orders', 'Returns policy', 'Contact support'],
            'productMatches' => [],
        ];
    }

    /**
     * @return array{reply: string, suggestions: list<string>, productMatches: list<array<string, mixed>>}
     */
    private function returnsHelp(): array
    {
        return [
            'reply' => 'Returns can be requested from a paid, delivered order. Open the order, choose Request return, and tell us the reason. Only one open return request is allowed per order.',
            'suggestions' => ['View my orders', 'Contact support', 'Search products'],
            'productMatches' => [],
        ];
    }

    /**
     * @return array{reply: string, suggestions: list<string>, productMatches: list<array<string, mixed>>}
     */
    private function shippingHelp(): array
    {
        return [
            'reply' => 'At checkout you can choose home delivery, click & collect, or store pickup depending on availability. Add a contact phone so we can reach you about your order.',
            'suggestions' => ['Store locations', 'Track my order', 'Contact support'],
            'productMatches' => [],
        ];
    }

    /**
     * @return array{reply: string, suggestions: list<string>, productMatches: list<array<string, mixed>>}
     */
    private function paymentHelp(): array
    {
        return [
            'reply' => 'We accept manual bank transfer confirmation and online card payments where enabled. Unpaid reservations expire after a limited time — complete payment from your order confirmation to secure stock.',
            'suggestions' => ['View my orders', 'Contact support', 'Search products'],
            'productMatches' => [],
        ];
    }

    /**
     * @return array{reply: string, suggestions: list<string>, productMatches: list<array<string, mixed>>}
     */
    private function accountHelp(): array
    {
        return [
            'reply' => 'Create an account with your email, verify the address we send you, then sign in to shop, save addresses, and manage orders. Use Account in the header to update your profile.',
            'suggestions' => ['Sign in', 'Register', 'Contact support'],
            'productMatches' => [],
        ];
    }

    /**
     * @return array{reply: string, suggestions: list<string>, productMatches: list<array<string, mixed>>}
     */
    private function storeInfoHelp(): array
    {
        $info = $this->supportInfo->build();
        $lines = [];

        if ($info['businessHours']) {
            $lines[] = 'Hours: '.$info['businessHours'];
        }

        foreach ($info['stores'] as $store) {
            $line = $store['name'];
            if ($store['address']) {
                $line .= ' — '.$store['address'];
            }
            $lines[] = $line;
        }

        if ($lines === []) {
            $lines[] = 'Visit our Contact page for store details and how to reach us.';
        }

        return [
            'reply' => implode("\n", $lines),
            'suggestions' => ['Open contact page', 'Delivery options', 'Search products'],
            'productMatches' => [],
        ];
    }

    /**
     * @return array{reply: string, suggestions: list<string>, productMatches: list<array<string, mixed>>}
     */
    private function productSearch(string $term): array
    {
        $organization = $this->organizations->resolve();
        $store = $this->stores->resolve($organization, null);
        $page = $this->catalogue->listProducts($organization, $term, null, 5);

        $matches = collect($page->items())->map(function (Product $product) use ($organization, $store) {
            $primary = $this->catalogue->primaryVariant($product);
            $price = $primary ? $this->catalogue->resolvePrice($organization, $primary, $store) : null;
            $image = $product->images->firstWhere('is_primary', true) ?? $product->images->first();

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'imageUrl' => $image?->url,
                'price' => $price,
            ];
        })->values()->all();

        if ($matches === []) {
            return [
                'reply' => 'I could not find products matching "'.$term.'". Try another name or SKU, or browse the catalogue.',
                'suggestions' => ['Search products', 'Contact support', 'Delivery options'],
                'productMatches' => [],
            ];
        }

        $names = collect($matches)->pluck('name')->take(3)->implode(', ');

        return [
            'reply' => 'Here are some products I found for "'.$term.'": '.$names.'.',
            'suggestions' => ['Search again', 'Contact support', 'View catalogue'],
            'productMatches' => $matches,
        ];
    }

    /**
     * @return array{reply: string, suggestions: list<string>, productMatches: list<array<string, mixed>>}
     */
    private function fallback(): array
    {
        return [
            'reply' => 'I am not sure about that yet. I can help with orders, returns, delivery, payments, store info, and product search — or you can message our team on the Contact page.',
            'suggestions' => [
                'Track my order',
                'Returns policy',
                'Open contact page',
                'Search products',
            ],
            'productMatches' => [],
        ];
    }

    /**
     * @param  list<string>  $needles
     */
    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function extractSearchTerm(string $normalized): ?string
    {
        $prefixes = [
            'search for ',
            'search ',
            'find ',
            'looking for ',
            'do you have ',
            'show me ',
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $term = trim(substr($normalized, strlen($prefix)));
                return $term !== '' ? $term : null;
            }
        }

        return null;
    }
}
