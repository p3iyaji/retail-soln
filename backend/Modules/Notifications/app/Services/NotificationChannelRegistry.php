<?php

namespace Modules\Notifications\Services;

use Illuminate\Contracts\Container\Container;
use Modules\Notifications\Contracts\NotificationChannel;
use RuntimeException;

/**
 * Resolves channel keys to adapters from `config('notifications.channels')`,
 * so deployments swap transports by config rather than by editing triggers.
 */
class NotificationChannelRegistry
{
    /** @var array<string, NotificationChannel> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function has(string $channel): bool
    {
        return is_string($this->classFor($channel));
    }

    public function get(string $channel): NotificationChannel
    {
        if (isset($this->resolved[$channel])) {
            return $this->resolved[$channel];
        }

        $class = $this->classFor($channel);
        if (! is_string($class)) {
            throw new RuntimeException("No notification channel adapter configured for [{$channel}].");
        }

        $adapter = $this->container->make($class);
        if (! $adapter instanceof NotificationChannel) {
            throw new RuntimeException("Channel adapter [{$class}] must implement NotificationChannel.");
        }

        return $this->resolved[$channel] = $adapter;
    }

    private function classFor(string $channel): ?string
    {
        $class = config('notifications.channels.'.$channel);

        return is_string($class) ? $class : null;
    }
}
