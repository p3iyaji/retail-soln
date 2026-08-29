<?php

namespace Modules\Sync\Support;

use Modules\Sync\Services\InvalidPullCursorException;

/**
 * Opaque store-scoped pull cursor (server-owned encoding).
 *
 * Wire format is an implementation detail — clients must only round-trip the string.
 */
final class PullCursor
{
    public const VERSION = 1;

    /** @var list<string> */
    public const RESOURCES = ['products', 'prices', 'taxes', 'customers', 'inventory', 'config'];

    /**
     * @param  array<string, array{at: string, id: int}|null>  $watermarks
     */
    public function __construct(
        public readonly int $storeId,
        public readonly array $watermarks,
    ) {}

    public static function empty(int $storeId): self
    {
        $watermarks = [];
        foreach (self::RESOURCES as $resource) {
            $watermarks[$resource] = null;
        }

        return new self($storeId, $watermarks);
    }

    public static function decode(?string $token, int $expectedStoreId): self
    {
        if ($token === null || $token === '') {
            return self::empty($expectedStoreId);
        }

        $json = self::base64UrlDecode($token);
        $payload = json_decode($json, true);
        if (! is_array($payload)) {
            throw new InvalidPullCursorException('Invalid sync cursor.');
        }

        if (($payload['v'] ?? null) !== self::VERSION) {
            throw new InvalidPullCursorException('Unsupported sync cursor version.');
        }

        $storeId = (int) ($payload['storeId'] ?? 0);
        if ($storeId !== $expectedStoreId) {
            throw new InvalidPullCursorException('Sync cursor does not belong to this store.');
        }

        $watermarks = [];
        foreach (self::RESOURCES as $resource) {
            $raw = $payload[$resource] ?? null;
            if ($raw === null) {
                $watermarks[$resource] = null;
                continue;
            }
            if (! is_array($raw) || ! isset($raw['at'], $raw['id'])) {
                throw new InvalidPullCursorException('Invalid sync cursor watermark.');
            }
            $watermarks[$resource] = [
                'at' => (string) $raw['at'],
                'id' => (int) $raw['id'],
            ];
        }

        return new self($storeId, $watermarks);
    }

    public function encode(): string
    {
        $payload = [
            'v' => self::VERSION,
            'storeId' => $this->storeId,
        ];
        foreach (self::RESOURCES as $resource) {
            $payload[$resource] = $this->watermarks[$resource];
        }

        return self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{at: string, id: int}|null
     */
    public function watermark(string $resource): ?array
    {
        return $this->watermarks[$resource] ?? null;
    }

    /**
     * @param  array{at: string, id: int}|null  $mark
     */
    public function withWatermark(string $resource, ?array $mark): self
    {
        $watermarks = $this->watermarks;
        $watermarks[$resource] = $mark;

        return new self($this->storeId, $watermarks);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $token): string
    {
        $padded = strtr($token, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new InvalidPullCursorException('Invalid sync cursor encoding.');
        }

        return $decoded;
    }
}
