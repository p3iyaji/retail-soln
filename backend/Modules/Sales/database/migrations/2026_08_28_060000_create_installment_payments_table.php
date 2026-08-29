<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->decimal('paid_amount', 15, 4)->default('0.0000')->after('amount');
        });

        Schema::create('installment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_plan_id')->constrained('installment_plans')->restrictOnDelete();
            $table->foreignId('installment_id')->constrained('installments')->restrictOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('cashier_sessions')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('method', 32);
            $table->string('provider', 32)->default('manual');
            $table->decimal('amount', 15, 4);
            $table->string('reference', 128)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['installment_plan_id']);
            $table->index(['installment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payments');
        Schema::table('installments', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};
