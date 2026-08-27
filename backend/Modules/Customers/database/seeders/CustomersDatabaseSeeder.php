<?php

namespace Modules\Customers\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Customers\Services\CustomerService;
use Modules\Organization\Services\OrganizationService;

class CustomersDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $organizationId = app(OrganizationService::class)->current()->id;
        app(CustomerService::class)->ensureWalkIn($organizationId);
    }
}
