<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_orders', function (Blueprint $table) {
            $table->string('payment_redirect_url', 2048)->nullable()->after('payment_reference');
            $table->unique(['organization_id', 'order_number']);
        });
    }

    public function down(): void
    {
        Schema::table('portal_orders', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'order_number']);
            $table->dropColumn('payment_redirect_url');
        });
    }
};
