<?php

namespace Modules\Organization\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Warehouse;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'store_id' => null,
            'name' => fake()->company().' Warehouse',
            'code' => strtoupper(fake()->unique()->bothify('WH-###')),
            'address_line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country' => 'US',
            'is_active' => true,
        ];
    }
}
