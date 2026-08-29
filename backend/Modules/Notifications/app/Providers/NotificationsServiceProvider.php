<?php

namespace Modules\Notifications\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Config (`config/config.php` → `config('notifications')`), views
 * (`notifications::`), and migrations are registered by the base provider.
 */
class NotificationsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Notifications';

    protected string $nameLower = 'notifications';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
