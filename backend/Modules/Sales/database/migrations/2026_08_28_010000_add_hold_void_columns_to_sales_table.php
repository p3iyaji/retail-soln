<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('status');
            $table->timestamp('voided_at')->nullable()->after('session_id');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->index(['organization_id', 'store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'store_id', 'status']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['notes', 'voided_at']);
        });
    }
};
