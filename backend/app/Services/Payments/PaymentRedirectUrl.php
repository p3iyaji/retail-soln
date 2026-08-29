<?php

namespace App\Services\Payments;

use Illuminate\Validation\ValidationException;

/**
 * Build and validate portal payment redirect URLs (no open redirects).
 */
class PaymentRedirectUrl
{
    /**
     * @return list<string>
     */
    public function allowedOrigins(): array
    {
        $raw = (string) config('payments.allowed_redirect_origins', '');
        $origins = array_values(array_filter(array_map(
            static fn (string $o): string => rtrim(trim($o), '/'),
            explode(',', $raw),
        )));

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '' && ! in_array($appUrl, $origins, true)) {
            $origins[] = $appUrl;
        }

        $portal = rtrim((string) config('payments.portal_frontend_url', ''), '/');
        if ($portal !== '' && ! in_array($portal, $origins, true)) {
            $origins[] = $portal;
        }

        return $origins;
    }

    /**
     * @throws ValidationException
     */
    public function assertAllowed(?string $url, string $field): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw ValidationException::withMessages([
                $field => ['URL must be an absolute http(s) URL.'],
            ]);
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::withMessages([
                $field => ['URL scheme must be http or https.'],
            ]);
        }

        $origin = $scheme.'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        $allowed = $this->allowedOrigins();
        foreach ($allowed as $candidate) {
            if (strcasecmp($origin, $candidate) === 0) {
                return;
            }
        }

        throw ValidationException::withMessages([
            $field => ['URL origin is not allowed.'],
        ]);
    }

    /**
     * Default confirmation URL with orderId + payment reference query params.
     */
    public function defaultSuccessUrl(int $orderId, string $paymentReference): string
    {
        $base = rtrim((string) config('payments.portal_frontend_url', config('app.url')), '/');

        return $base.'/shop/confirmation?orderId='.$orderId.'&payment_ref='.urlencode($paymentReference);
    }

    public function defaultCancelUrl(): string
    {
        $base = rtrim((string) config('payments.portal_frontend_url', config('app.url')), '/');

        return $base.'/shop/checkout';
    }

    /**
     * Ensure success URL includes orderId and payment_ref (append if missing).
     */
    public function withOrderContext(string $successUrl, int $orderId, string $paymentReference): string
    {
        $parts = parse_url($successUrl);
        if ($parts === false) {
            return $this->defaultSuccessUrl($orderId, $paymentReference);
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['orderId'] = (string) $orderId;
        $query['payment_ref'] = $paymentReference;

        $rebuild = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'localhost');
        if (isset($parts['port'])) {
            $rebuild .= ':'.$parts['port'];
        }
        $rebuild .= $parts['path'] ?? '/shop/confirmation';
        $rebuild .= '?'.http_build_query($query);
        if (isset($parts['fragment'])) {
            $rebuild .= '#'.$parts['fragment'];
        }

        return $rebuild;
    }

    /**
     * Resolve client-supplied or default success/cancel URLs, validated against allowlist.
     *
     * @return array{successUrl: string, cancelUrl: string}
     */
    public function resolve(?string $successUrl, ?string $cancelUrl, int $orderId, string $paymentReference): array
    {
        $this->assertAllowed($successUrl, 'successUrl');
        $this->assertAllowed($cancelUrl, 'cancelUrl');

        $success = ($successUrl !== null && $successUrl !== '')
            ? $this->withOrderContext($successUrl, $orderId, $paymentReference)
            : $this->defaultSuccessUrl($orderId, $paymentReference);

        $cancel = ($cancelUrl !== null && $cancelUrl !== '')
            ? $cancelUrl
            : $this->defaultCancelUrl();

        return [
            'successUrl' => $success,
            'cancelUrl' => $cancel,
        ];
    }
}
