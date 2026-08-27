<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('from_location_type', 32);
            $table->unsignedBigInteger('from_location_id');
            $table->string('to_location_type', 32);
            $table->unsignedBigInteger('to_location_id');
            $table->string('status', 32)->default('in_transit'); // in_transit|received|cancelled
            $table->foreignId('initiated_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['from_location_type', 'from_location_id']);
            $table->index(['to_location_type', 'to_location_id']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->foreignId('transfer_out_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('in_transit_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('transfer_in_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('in_transit_clear_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
