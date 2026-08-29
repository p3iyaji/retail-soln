<?php

namespace Modules\Products\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'organization_id' => $organizationId,
            'name' => fake()->words(3, true),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####??')),
            'barcode' => fake()->unique()->ean13(),
            'description' => fake()->optional()->sentence(),
            'selling_price' => fake()->randomFloat(4, 1, 500),
            'cost_price' => fake()->optional()->randomFloat(4, 0.5, 400),
            'reorder_level' => '5.0000',
            'min_stock' => '2.0000',
            'max_stock' => '100.0000',
            'is_active' => true,
        ];
    }
}
