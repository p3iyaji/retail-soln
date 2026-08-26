<?php

namespace Modules\Authentication\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Authentication\Models\Permission;
use Modules\Authentication\Models\Role;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AuthenticationServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Authentication';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'authentication';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(
            module_path($this->name, 'config/authentication.php'),
            'authentication'
        );
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        Gate::before(function ($user, string $ability) {
            if ($user === null || ! str_contains($ability, '.')) {
                return null;
            }

            return $user->hasPermission($ability);
        });
    }
}
