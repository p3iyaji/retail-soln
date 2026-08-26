<?php

namespace Modules\Organization\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Organization\Models\Organization;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->company().' Ltd',
            'default_currency' => 'USD',
            'timezone' => 'UTC',
        ];
    }
}
