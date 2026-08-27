<?php

namespace Modules\Pricing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Organization\Services\OrganizationService;
use Modules\Pricing\Models\PriceList;

class PricingDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        PriceList::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'store_id' => null,
                'name' => 'Retail',
            ],
            [
                'is_active' => true,
                'is_default' => true,
            ],
        );
    }
}
