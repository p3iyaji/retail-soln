<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Customers\Models\Customer;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceList;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Sync\Models\Device;
use Modules\Sync\Support\PullCursor;
use Tests\TestCase;

class SyncPullApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private Product $product;

    private ProductVariant $variant;

    private User $cashier;

    private string $deviceUuid = '550e8400-e29b-41d4-a716-446655440099';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(PermissionMatrixSeeder::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Pull Store',
            'currency' => 'USD',
            'tax_config' => [
                'rate' => '0.0750',
                'inclusive' => false,
                'name' => 'VAT',
            ],
        ]);

        $this->product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'PULL-P1',
            'name' => 'Pull Product',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $this->product->id,
            'name' => 'Default',
            'sku' => 'PULL-V1',
            'price' => '12.5000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: '5.0000',
            userId: User::factory()->inventoryManager()->create()->id,
            notes: 'Seed for pull tests',
        ));

        $this->cashier = User::factory()->cashier()->create();
        $this->cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($this->cashier, ['staff']);

        Device::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'uuid' => $this->deviceUuid,
            'name' => 'POS Pull',
            'enabled' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $deviceUuid = null): array
    {
        return [
            'X-Store-Id' => (string) $this->store->id,
            'X-Device-Uuid' => $deviceUuid ?? $this->deviceUuid,
        ];
    }

    private function pull(?string $cursor = null, ?int $limit = null, ?string $deviceUuid = null): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->cashier, ['staff']);

        $query = [];
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }
        if ($limit !== null) {
            $query['limit'] = $limit;
        }

        return $this->getJson('/api/v1/sync/pull'.($query !== [] ? '?'.http_build_query($query) : ''), $this->headers($deviceUuid));
    }

    public function test_empty_cursor_bootstraps_capped_page_and_returns_opaque_cursor(): void
    {
        $list = PriceList::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'name' => 'Store list',
            'is_active' => true,
            'is_default' => true,
        ]);
        Price::query()->create([
            'organization_id' => $this->orgId,
            'price_list_id' => $list->id,
            'product_variant_id' => $this->variant->id,
            'amount' => '11.0000',
        ]);

        Customer::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'registered',
            'name' => 'Ada',
            'phone' => '555',
            'account_number' => 'CUST-ADA',
            'is_active' => true,
        ]);

        $response = $this->pull(limit: 100);

        $response->assertOk()
            ->assertJsonPath('data.products.0.sku', 'PULL-P1')
            ->assertJsonPath('data.prices.0.unitPrice', '11.0000')
            ->assertJsonPath('data.taxes.0.rate', '0.0750')
            ->assertJsonPath('data.config.0.storeName', 'Pull Store')
            ->assertJsonPath('data.inventory.0.available', '5.0000')
            ->assertJsonPath('data.customers.0.name', 'Ada')
            ->assertJsonPath('meta.hasMore', false);

        $cursor = $response->json('meta.cursor');
        $this->assertIsString($cursor);
        $this->assertNotSame('', $cursor);
        // Opaque: client must not rely on structure; server can decode.
        $decoded = PullCursor::decode($cursor, $this->store->id);
        $this->assertSame($this->store->id, $decoded->storeId);

        $this->assertDatabaseHas('devices', [
            'uuid' => $this->deviceUuid,
            'enabled' => true,
            'store_id' => $this->store->id,
        ]);
    }

    public function test_incremental_pull_returns_only_delta(): void
    {
        $first = $this->pull(limit: 100);
        $first->assertOk();
        $cursor = $first->json('meta.cursor');

        $this->travel(2)->seconds();
        $this->product->forceFill(['name' => 'Renamed Product'])->save();

        $second = $this->pull($cursor);
        $second->assertOk();
        $second->assertJsonPath('data.products.0.name', 'Renamed Product');
        $this->assertCount(1, $second->json('data.products'));
        $this->assertSame([], $second->json('data.prices'));
        $this->assertNotSame($cursor, $second->json('meta.cursor'));
    }

    public function test_inactive_product_appears_as_delete(): void
    {
        $first = $this->pull();
        $cursor = $first->json('meta.cursor');

        $this->travel(2)->seconds();
        $this->product->forceFill(['is_active' => false])->save();

        $second = $this->pull($cursor);
        $second->assertOk()
            ->assertJsonPath('data.deleted.products.0', $this->product->id)
            ->assertJsonPath('data.products.0.isActive', false);
    }

    public function test_soft_deleted_customer_appears_in_deleted(): void
    {
        $customer = Customer::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'registered',
            'name' => 'Temp',
            'account_number' => 'CUST-TEMP',
            'is_active' => true,
        ]);

        $first = $this->pull();
        $cursor = $first->json('meta.cursor');

        $this->travel(2)->seconds();
        $customer->delete();

        $second = $this->pull($cursor);
        $second->assertOk();
        $this->assertContains($customer->id, $second->json('data.deleted.customers'));
    }

    public function test_disabled_device_returns_403(): void
    {
        Device::query()
            ->where('organization_id', $this->orgId)
            ->where('uuid', $this->deviceUuid)
            ->update(['enabled' => false, 'last_seen_at' => now()]);

        $this->pull()->assertForbidden()
            ->assertJsonPath('code', 'device_disabled');
    }

    public function test_unregistered_device_returns_403_without_creating_row(): void
    {
        $unknown = '550e8400-e29b-41d4-a716-4466554400ee';
        $before = Device::query()->count();

        $this->pull(deviceUuid: $unknown)->assertForbidden()
            ->assertJsonPath('code', 'device_unregistered');

        $this->assertSame($before, Device::query()->count());
        $this->assertDatabaseMissing('devices', ['uuid' => $unknown]);
    }

    public function test_cursor_store_mismatch_returns_422(): void
    {
        $otherStore = Store::factory()->create(['organization_id' => $this->orgId]);
        $foreign = PullCursor::empty($otherStore->id)->encode();

        $this->pull($foreign)->assertStatus(422)
            ->assertJsonPath('code', 'invalid_cursor');
    }

    public function test_has_more_when_limit_exceeded(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Product::factory()->create([
                'organization_id' => $this->orgId,
                'sku' => 'PULL-EXTRA-'.$i,
                'is_active' => true,
            ]);
        }

        $response = $this->pull(limit: 2);
        $response->assertOk()->assertJsonPath('meta.hasMore', true);
        $this->assertLessThanOrEqual(2, count($response->json('data.products')));

        $cursor = $response->json('meta.cursor');
        $next = $this->pull($cursor, limit: 100);
        $next->assertOk();
        $this->assertGreaterThanOrEqual(1, count($next->json('data.products')));
    }

    public function test_device_uuid_via_query_param(): void
    {
        Sanctum::actingAs($this->cashier, ['staff']);
        $uuid = '550e8400-e29b-41d4-a716-446655440088';
        Device::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'uuid' => $uuid,
            'name' => 'Query param device',
            'enabled' => true,
        ]);

        $response = $this->getJson(
            '/api/v1/sync/pull?'.http_build_query(['deviceUuid' => $uuid]),
            ['X-Store-Id' => (string) $this->store->id],
        );

        $response->assertOk();
        $this->assertDatabaseHas('devices', ['uuid' => $uuid, 'enabled' => true]);
    }

    public function test_prefers_store_price_over_variant_list_price(): void
    {
        $list = PriceList::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'name' => 'Promo',
            'is_active' => true,
            'is_default' => false,
        ]);
        Price::query()->create([
            'organization_id' => $this->orgId,
            'price_list_id' => $list->id,
            'product_variant_id' => $this->variant->id,
            'amount' => '9.9900',
        ]);

        $response = $this->pull();
        $response->assertOk()
            ->assertJsonPath('data.prices.0.unitPrice', '9.9900');
        $this->assertSame('12.5000', $response->json('data.products.0.variants.0.price'));
    }

    public function test_incremental_pull_returns_price_delta_after_cursor(): void
    {
        $list = PriceList::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'name' => 'Store list',
            'is_active' => true,
            'is_default' => true,
        ]);
        $price = Price::query()->create([
            'organization_id' => $this->orgId,
            'price_list_id' => $list->id,
            'product_variant_id' => $this->variant->id,
            'amount' => '10.0000',
        ]);

        $first = $this->pull();
        $first->assertOk();
        $cursor = $first->json('meta.cursor');

        $this->travel(2)->seconds();
        $price->forceFill(['amount' => '14.2500'])->save();

        $second = $this->pull($cursor);
        $second->assertOk()
            ->assertJsonPath('data.prices.0.unitPrice', '14.2500')
            ->assertJsonPath('data.prices.0.variantId', $this->variant->id);
        $this->assertSame([], $second->json('data.products'));
        $this->assertNotSame($cursor, $second->json('meta.cursor'));
    }
}
