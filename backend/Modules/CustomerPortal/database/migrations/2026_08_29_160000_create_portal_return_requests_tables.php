<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_return_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignId('portal_order_id')->constrained('portal_orders')->cascadeOnDelete();
            $table->string('status', 32)->default('requested');
            $table->string('reason', 32);
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->json('request_fingerprint')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['customer_account_id', 'status']);
            $table->index(['portal_order_id', 'status']);
        });

        Schema::create('portal_return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_return_request_id')
                ->constrained('portal_return_requests')
                ->cascadeOnDelete();
            $table->foreignId('portal_order_item_id')
                ->constrained('portal_order_items')
                ->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->timestamps();

            $table->unique(
                ['portal_return_request_id', 'portal_order_item_id'],
                'portal_return_req_items_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_return_request_items');
        Schema::dropIfExists('portal_return_requests');
    }
};
