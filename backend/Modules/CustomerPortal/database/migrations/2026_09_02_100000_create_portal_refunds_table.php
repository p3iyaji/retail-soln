<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->foreignId('portal_return_request_id')
                ->constrained('portal_return_requests')
                ->cascadeOnDelete();
            $table->foreignId('portal_order_id')
                ->constrained('portal_orders')
                ->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('method', 32);
            $table->decimal('amount', 14, 4);
            $table->string('status', 32)->default('completed');
            $table->string('reference', 128)->nullable();
            $table->string('gateway_reference', 128)->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['portal_order_id', 'status']);
            $table->index(['gateway_reference']);
        });

        Schema::table('portal_return_requests', function (Blueprint $table) {
            $table->decimal('refund_total', 14, 4)->nullable()->after('notes');
            $table->timestamp('completed_at')->nullable()->after('refund_total');
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->string('complete_idempotency_key', 64)->nullable()->after('completed_by');
            $table->unique(['organization_id', 'complete_idempotency_key'], 'portal_return_req_org_complete_idem');
        });

        Schema::table('portal_return_request_items', function (Blueprint $table) {
            $table->foreignId('inventory_movement_id')
                ->nullable()
                ->after('quantity')
                ->constrained('inventory_movements')
                ->nullOnDelete();
            $table->decimal('line_total', 14, 4)->nullable()->after('inventory_movement_id');
        });
    }

    public function down(): void
    {
        Schema::table('portal_return_request_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_movement_id');
            $table->dropColumn('line_total');
        });

        Schema::table('portal_return_requests', function (Blueprint $table) {
            $table->dropUnique('portal_return_req_org_complete_idem');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['refund_total', 'completed_at', 'complete_idempotency_key']);
        });

        Schema::dropIfExists('portal_refunds');
    }
};
