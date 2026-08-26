<?php

namespace Modules\Organization\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->company().' Store',
            'code' => strtoupper(fake()->unique()->bothify('ST-###')),
            'address_line1' => fake()->streetAddress(),
            'address_line2' => null,
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country' => 'US',
            'currency' => 'USD',
            'tax_config' => [
                'rate' => '0.0750',
                'inclusive' => false,
                'name' => 'Sales Tax',
            ],
            'is_active' => true,
        ];
    }
}
