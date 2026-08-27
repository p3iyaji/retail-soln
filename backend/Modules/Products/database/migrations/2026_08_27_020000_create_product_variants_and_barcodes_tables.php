<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku');
            $table->decimal('price', 15, 4);
            $table->decimal('cost_price', 15, 4)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'sku']);
            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['product_variant_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barcodes');
        Schema::dropIfExists('product_variants');
    }
};
