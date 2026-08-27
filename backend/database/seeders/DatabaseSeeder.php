<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionMatrixSeeder::class,
            DevelopmentUsersSeeder::class,
            \Modules\Pricing\Database\Seeders\PricingDatabaseSeeder::class,
        ]);
    }
}
