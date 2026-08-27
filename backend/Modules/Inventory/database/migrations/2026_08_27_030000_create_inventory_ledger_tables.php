<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('location_type', 32); // store | warehouse
            $table->unsignedBigInteger('location_id');
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('available', 15, 4)->default(0);
            $table->decimal('reserved', 15, 4)->default(0);
            $table->decimal('damaged', 15, 4)->default(0);
            $table->decimal('in_transit', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'location_type', 'location_id', 'product_variant_id'],
                'inventory_balances_org_location_variant_unique'
            );
            $table->index(['location_type', 'location_id', 'product_variant_id'], 'inventory_balances_location_variant_index');
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('location_type', 32);
            $table->unsignedBigInteger('location_id');
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->decimal('quantity', 15, 4); // signed
            $table->string('bucket', 20)->default('available'); // available|reserved|damaged|in_transit
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('balance_after', 15, 4)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['location_type', 'location_id', 'product_variant_id'], 'inventory_movements_location_variant_index');
            $table->index(['reference_type', 'reference_id']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_balances');
    }
};
