<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;

/**
 * Idempotent data for Playwright POS flows — safe to re-run.
 */
class E2ePlaywrightSeeder extends Seeder
{
    public function run(): void
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        $store = Store::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'code' => 'E2E-DEMO',
            ],
            [
                'name' => 'Demo Store',
                'currency' => 'USD',
                'tax_config' => [
                    'rate' => '0.0750',
                    'inclusive' => false,
                    'name' => 'VAT',
                ],
                'is_active' => true,
            ],
        );

        $admin = User::query()->where('email', 'admin@retail.local')->first();
        if ($admin !== null) {
            $admin->stores()->syncWithoutDetaching([
                $store->id => ['is_default' => true],
            ]);
        }
    }
}
