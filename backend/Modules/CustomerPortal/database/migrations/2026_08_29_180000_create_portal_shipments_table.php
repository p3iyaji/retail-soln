<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_shipments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->foreignId('portal_order_id')->constrained('portal_orders')->cascadeOnDelete();
            $table->string('carrier_name', 120)->nullable();
            $table->string('tracking_number', 120)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('ship_idempotency_key', 64)->nullable();
            $table->string('deliver_idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique('portal_order_id');
            $table->index(['organization_id', 'shipped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_shipments');
    }
};
