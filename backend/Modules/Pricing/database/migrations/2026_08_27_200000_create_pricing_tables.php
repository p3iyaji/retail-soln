<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'store_id', 'is_active']);
            $table->index(['organization_id', 'is_default']);
        });

        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            $table->unique(['price_list_id', 'product_variant_id']);
            $table->index(['organization_id', 'product_variant_id']);
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'store_id', 'is_active']);
        });

        Schema::create('promotion_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('value', 15, 4)->default(0);
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('buy_qty', 15, 4)->nullable();
            $table->decimal('get_qty', 15, 4)->nullable();
            $table->decimal('get_discount_percent', 15, 4)->nullable();
            $table->timestamps();

            $table->index(['promotion_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_rules');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('prices');
        Schema::dropIfExists('price_lists');
    }
};
