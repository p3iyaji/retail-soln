<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HotPathPerformanceIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_hot_path_indexes_exist_after_migration(): void
    {
        $this->assertTrue(Schema::hasIndex('sales', 'sales_org_store_status_created_index'));
        $this->assertTrue(Schema::hasIndex('products', 'products_org_active_updated_index'));
        $this->assertTrue(Schema::hasIndex('customers', 'customers_org_updated_index'));
        $this->assertTrue(Schema::hasIndex('prices', 'prices_org_updated_index'));
        $this->assertTrue(Schema::hasIndex('portal_orders', 'portal_orders_fulfilment_queue_index'));
        $this->assertTrue(Schema::hasIndex('sale_payments', 'sale_payments_org_method_index'));
    }
}
