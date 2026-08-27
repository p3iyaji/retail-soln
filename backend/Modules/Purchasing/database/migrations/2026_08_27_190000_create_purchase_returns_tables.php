<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: local MySQL may already have these tables without a migrations row.
        if (! Schema::hasTable('purchase_returns')) {
            Schema::create('purchase_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
                $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
                $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
                $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
                $table->decimal('credit_amount', 15, 4);
                $table->text('notes')->nullable();
                $table->foreignId('returned_by')->constrained('users')->restrictOnDelete();
                $table->string('idempotency_key', 64)->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'idempotency_key']);
                $table->index(['organization_id', 'supplier_id']);
                $table->index(['organization_id', 'goods_receipt_id']);
                $table->index(['organization_id', 'purchase_order_id']);
            });
        }

        if (! Schema::hasTable('purchase_return_items')) {
            Schema::create('purchase_return_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
                $table->foreignId('goods_receipt_item_id')->constrained('goods_receipt_items')->restrictOnDelete();
                $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
                $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
                $table->decimal('qty', 15, 4);
                $table->decimal('unit_cost', 15, 4);
                $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
                $table->timestamps();

                $table->index(['purchase_return_id']);
                $table->index(['organization_id', 'goods_receipt_item_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
