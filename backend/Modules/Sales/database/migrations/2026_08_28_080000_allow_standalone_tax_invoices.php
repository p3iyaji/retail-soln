<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
            $table->unsignedBigInteger('sale_id')->nullable()->change();
            $table->foreign('sale_id')->references('id')->on('sales')->restrictOnDelete();
            $table->foreignId('proforma_invoice_id')->nullable()->after('sale_id')
                ->constrained('proforma_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['proforma_invoice_id']);
            $table->dropColumn('proforma_invoice_id');
            $table->dropForeign(['sale_id']);
            $table->unsignedBigInteger('sale_id')->nullable(false)->change();
            $table->foreign('sale_id')->references('id')->on('sales')->restrictOnDelete();
        });
    }
};
