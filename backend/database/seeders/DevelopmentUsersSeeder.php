<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Organization\Services\OrganizationService;

/**
 * Local/dev login accounts. Idempotent — safe to re-run via `php artisan db:seed`.
 *
 * Password for all accounts: password
 */
class DevelopmentUsersSeeder extends Seeder
{
    public function run(): void
    {
        app(OrganizationService::class)->current();

        $accounts = [
            [
                'name' => 'Org Admin',
                'email' => 'admin@retail.local',
                'role' => 'org_admin',
            ],
            [
                'name' => 'Inventory Manager',
                'email' => 'inventory@retail.local',
                'role' => 'inventory_manager',
            ],
            [
                'name' => 'Procurement Officer',
                'email' => 'procurement@retail.local',
                'role' => 'procurement_officer',
            ],
        ];

        foreach ($accounts as $account) {
            User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => 'password',
                    'role' => $account['role'],
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
