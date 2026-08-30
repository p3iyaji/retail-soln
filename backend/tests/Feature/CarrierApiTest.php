<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\CustomerPortal\Models\Carrier;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Models\PortalShipment;
use Modules\Customers\Enums\CustomerType;
use Modules\Customers\Models\Customer;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Tests\TestCase;

class CarrierApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
    }

    public function test_org_admin_can_create_and_list_carriers(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->postJson('/api/v1/carriers', [
            'name' => 'Swift Logistics',
            'code' => 'SWIFT',
            'trackingUrlTemplate' => 'https://track.example.com/{tracking}',
            'isActive' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Swift Logistics')
            ->assertJsonPath('data.code', 'SWIFT')
            ->assertJsonPath('data.trackingUrlTemplate', 'https://track.example.com/{tracking}');

        $this->getJson('/api/v1/carriers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Swift Logistics')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_store_manager_can_update_carrier(): void
    {
        Sanctum::actingAs(User::factory()->storeManager()->create(), ['staff']);

        $carrier = Carrier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Old Name',
            'code' => 'OLD',
            'is_active' => true,
        ]);

        $this->patchJson("/api/v1/carriers/{$carrier->id}", [
            'name' => 'Updated Courier',
            'isActive' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Courier')
            ->assertJsonPath('data.isActive', false);
    }

    public function test_cashier_cannot_manage_carriers(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);

        $this->postJson('/api/v1/carriers', ['name' => 'Denied'])
            ->assertForbidden();
    }

    public function test_delete_deactivates_carrier_with_shipments(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $carrier = Carrier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Used Carrier',
            'is_active' => true,
        ]);

        PortalShipment::query()->create([
            'organization_id' => $this->orgId,
            'portal_order_id' => $this->createPortalOrderId(),
            'carrier_id' => $carrier->id,
            'carrier_name' => $carrier->name,
            'shipped_at' => now(),
        ]);

        $this->deleteJson("/api/v1/carriers/{$carrier->id}")
            ->assertOk()
            ->assertJsonPath('meta.deactivated', true)
            ->assertJsonPath('data.isActive', false);

        $this->assertDatabaseHas('carriers', [
            'id' => $carrier->id,
            'is_active' => false,
        ]);
    }

    private function createPortalOrderId(): int
    {
        $store = Store::factory()->create([
            'organization_id' => $this->orgId,
        ]);

        $customer = Customer::query()->create([
            'organization_id' => $this->orgId,
            'type' => CustomerType::Registered,
            'name' => 'Carrier Test',
            'account_number' => 'CUST-CARRIER-1',
            'is_active' => true,
        ]);

        $account = CustomerAccount::query()->create([
            'organization_id' => $this->orgId,
            'customer_id' => $customer->id,
            'email' => 'carrier-test@example.test',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        return PortalOrder::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'customer_account_id' => $account->id,
            'uuid' => (string) Str::uuid(),
            'order_number' => 'PO-CARRIER-1',
            'status' => 'out_for_delivery',
            'payment_status' => 'paid',
            'fulfilment_mode' => 'home_delivery',
            'subtotal' => '10.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'delivery_fee' => '0.0000',
            'total' => '10.0000',
            'paid_at' => now(),
        ])->id;
    }
}
