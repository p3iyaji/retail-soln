<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductVariantProvisioner;

return new class extends Migration
{
    public function up(): void
    {
        $provisioner = app(ProductVariantProvisioner::class);

        Product::query()
            ->whereDoesntHave('variants')
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($provisioner): void {
                foreach ($products as $product) {
                    $provisioner->ensureDefaultVariant($product);
                }
            });
    }

    public function down(): void
    {
        // Non-reversible data backfill.
    }
};
