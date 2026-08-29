<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->uuid('uuid')->unique();
            $table->string('order_number', 32);
            $table->string('status', 32)->default('pending');
            $table->string('payment_status', 32)->default('awaiting_payment');
            $table->string('fulfilment_mode', 32);
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_address_id')->nullable();
            $table->string('contact_phone', 64)->nullable();
            $table->decimal('subtotal', 14, 4);
            $table->decimal('discount_total', 14, 4)->default('0.0000');
            $table->decimal('tax_total', 14, 4);
            $table->decimal('delivery_fee', 14, 4)->default('0.0000');
            $table->decimal('total', 14, 4);
            $table->string('payment_provider', 32)->nullable();
            $table->string('payment_reference', 128)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->json('request_fingerprint')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'customer_account_id']);
            $table->index('order_number');
            $table->index('payment_reference');
        });

        Schema::create('portal_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_order_id')->constrained('portal_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('product_variant_id');
            $table->string('product_name');
            $table->string('variant_name');
            $table->string('sku', 64);
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_list_price', 14, 4);
            $table->decimal('unit_net_price', 14, 4);
            $table->decimal('unit_gross_price', 14, 4);
            $table->decimal('line_tax', 14, 4);
            $table->decimal('line_total', 14, 4);
            $table->timestamps();

            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_order_items');
        Schema::dropIfExists('portal_orders');
    }
};
