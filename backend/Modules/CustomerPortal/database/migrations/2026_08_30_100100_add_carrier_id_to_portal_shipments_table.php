<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_shipments', function (Blueprint $table): void {
            $table->foreignId('carrier_id')->nullable()->after('portal_order_id')->constrained('carriers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('portal_shipments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('carrier_id');
        });
    }
};
