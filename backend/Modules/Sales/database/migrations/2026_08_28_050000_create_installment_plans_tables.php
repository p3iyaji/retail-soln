<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->decimal('principal', 15, 4);
            $table->decimal('fee_total', 15, 4)->default('0.0000');
            $table->string('mode', 32)->default('pay_over_time');
            $table->string('status', 32)->default('open');
            $table->timestamps();

            $table->index(['organization_id', 'customer_id', 'status']);
            $table->index(['organization_id', 'store_id', 'status']);
            $table->index(['sale_id']);
        });

        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_plan_id')->constrained('installment_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->decimal('amount', 15, 4);
            $table->date('due_at');
            $table->string('status', 32)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->timestamps();

            $table->unique(['installment_plan_id', 'sequence']);
            $table->index(['due_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
        Schema::dropIfExists('installment_plans');
    }
};
