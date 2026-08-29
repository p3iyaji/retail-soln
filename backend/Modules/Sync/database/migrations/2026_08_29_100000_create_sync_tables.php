<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid');
            $table->string('name')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'uuid']);
            $table->index(['organization_id', 'store_id', 'enabled']);
        });

        Schema::create('sync_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('record_count')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'store_id', 'created_at']);
        });

        Schema::create('sync_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_batch_id')->nullable()->constrained('sync_batches')->nullOnDelete();
            $table->uuid('uuid');
            $table->unsignedBigInteger('local_sequence')->nullable();
            $table->string('payload_type', 32);
            $table->string('status', 16);
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('exception_id')->nullable();
            $table->json('errors')->nullable();
            $table->json('server_result')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'uuid']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('inventory_oversell_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty_deficit', 15, 4);
            $table->string('status', 16)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'store_id', 'status']);
            $table->index(['sale_id']);
        });

        Schema::table('sync_records', function (Blueprint $table) {
            $table->foreign('exception_id')
                ->references('id')
                ->on('inventory_oversell_exceptions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sync_records', function (Blueprint $table) {
            $table->dropForeign(['exception_id']);
        });
        Schema::dropIfExists('inventory_oversell_exceptions');
        Schema::dropIfExists('sync_records');
        Schema::dropIfExists('sync_batches');
        Schema::dropIfExists('devices');
    }
};
