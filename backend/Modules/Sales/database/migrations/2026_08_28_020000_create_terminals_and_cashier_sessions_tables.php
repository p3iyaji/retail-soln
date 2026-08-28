<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->uuid('uuid');
            $table->string('name', 64);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'store_id', 'name']);
            $table->unique(['organization_id', 'uuid']);
            $table->index(['organization_id', 'store_id']);
        });

        Schema::create('cashier_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('open');
            $table->decimal('opening_float', 15, 4);
            $table->decimal('expected_cash', 15, 4)->nullable();
            $table->decimal('closing_cash', 15, 4)->nullable();
            $table->decimal('variance', 15, 4)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'store_id', 'status']);
            $table->index(['terminal_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('cashier_sessions')->cascadeOnDelete();
            $table->string('type', 32);
            $table->decimal('amount', 15, 4);
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['session_id', 'type']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreign('terminal_id')->references('id')->on('terminals')->nullOnDelete();
            $table->foreign('session_id')->references('id')->on('cashier_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['terminal_id']);
            $table->dropForeign(['session_id']);
        });

        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cashier_sessions');
        Schema::dropIfExists('terminals');
    }
};
