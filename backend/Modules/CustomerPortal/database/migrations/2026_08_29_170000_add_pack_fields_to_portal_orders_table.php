<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_orders', function (Blueprint $table): void {
            $table->timestamp('packed_at')->nullable()->after('paid_at');
            $table->string('pack_idempotency_key', 64)->nullable()->after('packed_at');
        });
    }

    public function down(): void
    {
        Schema::table('portal_orders', function (Blueprint $table): void {
            $table->dropColumn(['packed_at', 'pack_idempotency_key']);
        });
    }
};
