<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->uuid('uuid');
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('reason', 64);
            $table->decimal('total', 15, 4);
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'uuid']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'store_id']);
            $table->index(['sale_id']);
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_return_id')->constrained('sale_returns')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount', 15, 4)->default('0.0000');
            $table->decimal('tax_amount', 15, 4)->default('0.0000');
            $table->decimal('line_total', 15, 4);
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->timestamps();

            $table->index(['sale_return_id']);
            $table->index(['sale_item_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_return_id')->constrained('sale_returns')->cascadeOnDelete();
            $table->string('method', 32);
            $table->string('provider', 32)->default('manual');
            $table->decimal('amount', 15, 4);
            $table->string('reference', 128)->nullable();
            $table->timestamps();

            $table->index(['sale_return_id']);
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->foreign('session_id')->references('id')->on('cashier_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropForeign(['session_id']);
        });

        Schema::dropIfExists('refunds');
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};
