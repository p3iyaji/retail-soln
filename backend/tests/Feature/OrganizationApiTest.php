<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Models\Warehouse;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Modules\Authentication\Database\Seeders\PermissionMatrixSeeder::class);
    }

    public function test_org_admin_can_get_and_update_current_organization(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        Sanctum::actingAs($admin, ['staff']);

        $this->getJson('/api/v1/organizations/current')
            ->assertOk()
            ->assertJsonPath('data.name', config('app.name'))
            ->assertJsonPath('data.defaultCurrency', 'USD');

        $this->postJson('/api/v1/organizations/current', [
            'name' => 'Acme Retail',
            'legalName' => 'Acme Retail Ltd',
            'defaultCurrency' => 'ngn',
            'timezone' => 'Africa/Lagos',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Retail')
            ->assertJsonPath('data.legalName', 'Acme Retail Ltd')
            ->assertJsonPath('data.defaultCurrency', 'NGN')
            ->assertJsonPath('data.timezone', 'Africa/Lagos');

        $this->assertDatabaseHas('organizations', [
            'name' => 'Acme Retail',
            'default_currency' => 'NGN',
        ]);
    }

    public function test_cashier_cannot_update_organization(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'cashier']), ['staff']);

        $this->postJson('/api/v1/organizations/current', [
            'name' => 'Hacked Org',
        ])->assertForbidden();
    }

    public function test_org_admin_can_crud_stores_with_address_currency_tax(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $create = $this->postJson('/api/v1/stores', [
            'name' => 'Downtown',
            'code' => 'DTN',
            'addressLine1' => '12 Market St',
            'city' => 'Lagos',
            'country' => 'NG',
            'currency' => 'NGN',
            'taxConfig' => [
                'rate' => '0.0750',
                'inclusive' => false,
                'name' => 'VAT',
            ],
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.name', 'Downtown')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.taxConfig.rate', '0.0750')
            ->assertJsonPath('data.addressLine1', '12 Market St');

        $storeId = $create->json('data.id');
        $this->assertNotNull(Organization::query()->first());
        $this->assertDatabaseHas('stores', [
            'id' => $storeId,
            'organization_id' => Organization::query()->first()->id,
            'currency' => 'NGN',
        ]);

        $this->getJson('/api/v1/stores')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->patchJson("/api/v1/stores/{$storeId}", [
            'name' => 'Downtown Flagship',
            'currency' => 'USD',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Downtown Flagship')
            ->assertJsonPath('data.currency', 'USD');

        $this->deleteJson("/api/v1/stores/{$storeId}")
            ->assertOk();

        $this->assertDatabaseMissing('stores', ['id' => $storeId]);
    }

    public function test_cashier_cannot_create_store(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'cashier']), ['staff']);

        $this->postJson('/api/v1/stores', [
            'name' => 'Unauthorized Store',
            'currency' => 'USD',
        ])->assertForbidden();
    }

    public function test_org_admin_can_crud_warehouses_belonging_to_organization(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);
        $organization = Organization::factory()->create();
        $store = Store::factory()->create(['organization_id' => $organization->id]);

        // Force "current" org to be the one we created (first by id)
        Organization::query()->where('id', '!=', $organization->id)->delete();

        $create = $this->postJson('/api/v1/warehouses', [
            'name' => 'Central WH',
            'code' => 'CWH',
            'storeId' => $store->id,
            'city' => 'Abuja',
            'country' => 'NG',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.name', 'Central WH')
            ->assertJsonPath('data.storeId', $store->id)
            ->assertJsonPath('data.organizationId', $organization->id);

        $warehouseId = $create->json('data.id');

        $this->patchJson("/api/v1/warehouses/{$warehouseId}", [
            'name' => 'Central Warehouse',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Central Warehouse');

        $this->getJson('/api/v1/warehouses')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->deleteJson("/api/v1/warehouses/{$warehouseId}")
            ->assertOk();

        $this->assertDatabaseMissing('warehouses', ['id' => $warehouseId]);
    }

    public function test_cashier_cannot_delete_warehouse(): void
    {
        $organization = Organization::factory()->create();
        $warehouse = Warehouse::factory()->create(['organization_id' => $organization->id]);

        Sanctum::actingAs(User::factory()->create(['role' => 'cashier']), ['staff']);

        $this->deleteJson("/api/v1/warehouses/{$warehouse->id}")
            ->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/organizations/current')->assertUnauthorized();
        $this->getJson('/api/v1/stores')->assertUnauthorized();
        $this->getJson('/api/v1/warehouses')->assertUnauthorized();
    }
}
