<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'store_id', 'status', 'created_at'],
                'sales_org_store_status_created_index',
            );
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'is_active', 'updated_at', 'id'],
                'products_org_active_updated_index',
            );
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'updated_at', 'id'],
                'customers_org_updated_index',
            );
        });

        Schema::table('prices', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'updated_at', 'id'],
                'prices_org_updated_index',
            );
        });

        Schema::table('portal_orders', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'store_id', 'payment_status', 'status'],
                'portal_orders_fulfilment_queue_index',
            );
        });

        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'method'],
                'sale_payments_org_method_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropIndex('sale_payments_org_method_index');
        });

        Schema::table('portal_orders', function (Blueprint $table): void {
            $table->dropIndex('portal_orders_fulfilment_queue_index');
        });

        Schema::table('prices', function (Blueprint $table): void {
            $table->dropIndex('prices_org_updated_index');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_org_updated_index');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_org_active_updated_index');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex('sales_org_store_status_created_index');
        });
    }
};
