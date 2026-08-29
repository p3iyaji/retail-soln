<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proforma_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('number', 64)->nullable();
            $table->string('status', 32)->default('draft');
            $table->decimal('subtotal', 15, 4)->default('0.0000');
            $table->decimal('discount_total', 15, 4)->default('0.0000');
            $table->decimal('tax_total', 15, 4)->default('0.0000');
            $table->decimal('total', 15, 4)->default('0.0000');
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('converted_sale_id')->nullable();
            $table->unsignedBigInteger('converted_invoice_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'store_id', 'status']);
            $table->index(['organization_id', 'customer_id']);
        });

        Schema::create('proforma_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proforma_invoice_id')->constrained('proforma_invoices')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount', 15, 4)->default('0.0000');
            $table->decimal('tax_amount', 15, 4)->default('0.0000');
            $table->decimal('line_total', 15, 4);
            $table->timestamps();

            $table->index(['proforma_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proforma_invoice_items');
        Schema::dropIfExists('proforma_invoices');
    }
};
