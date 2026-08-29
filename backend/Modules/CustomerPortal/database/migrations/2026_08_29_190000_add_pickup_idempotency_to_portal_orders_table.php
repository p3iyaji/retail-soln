<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_orders', function (Blueprint $table): void {
            $table->string('ready_idempotency_key', 64)->nullable()->after('pack_idempotency_key');
            $table->string('collect_idempotency_key', 64)->nullable()->after('ready_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('portal_orders', function (Blueprint $table): void {
            $table->dropColumn(['ready_idempotency_key', 'collect_idempotency_key']);
        });
    }
};
