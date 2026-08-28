<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->uuid('uuid');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->decimal('subtotal', 15, 4);
            $table->decimal('discount_total', 15, 4)->default('0.0000');
            $table->decimal('tax_total', 15, 4)->default('0.0000');
            $table->decimal('total', 15, 4);
            $table->string('status', 32)->default('completed');
            $table->string('idempotency_key', 64)->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'uuid']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'store_id']);
            $table->index(['organization_id', 'customer_id']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount', 15, 4)->default('0.0000');
            $table->decimal('tax_amount', 15, 4)->default('0.0000');
            $table->decimal('line_total', 15, 4);
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->timestamps();

            $table->index(['sale_id']);
            $table->index(['organization_id', 'product_variant_id']);
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('method', 32);
            $table->string('provider', 32)->default('manual');
            $table->decimal('amount', 15, 4);
            $table->string('reference', 128)->nullable();
            $table->timestamps();

            $table->index(['sale_id']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->string('invoice_number', 64)->nullable();
            $table->string('status', 32)->default('issued');
            $table->decimal('subtotal', 15, 4);
            $table->decimal('tax_total', 15, 4)->default('0.0000');
            $table->decimal('total', 15, 4);
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'sale_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount', 15, 4)->default('0.0000');
            $table->decimal('tax_amount', 15, 4)->default('0.0000');
            $table->decimal('line_total', 15, 4);
            $table->timestamps();

            $table->index(['invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
