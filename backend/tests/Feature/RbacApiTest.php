<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Tests\TestCase;

class RbacApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
    }

    public function test_permission_matrix_includes_sales_refund_for_store_manager_not_cashier(): void
    {
        $manager = User::factory()->storeManager()->create();
        $cashier = User::factory()->cashier()->create();

        $this->assertTrue($manager->hasPermission('sales.refund'));
        $this->assertFalse($cashier->hasPermission('sales.refund'));
        $this->assertTrue($manager->hasPermission('fulfilment.pick'));
        $this->assertTrue($manager->hasPermission('fulfilment.ship'));
        $this->assertTrue($manager->hasPermission('fulfilment.pickup'));
        $this->assertFalse($cashier->hasPermission('fulfilment.pick'));
        $this->assertFalse($cashier->hasPermission('fulfilment.ship'));
        $this->assertFalse($cashier->hasPermission('fulfilment.pickup'));
    }

    public function test_me_returns_matrix_permissions_when_role_pivot_empty(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $role = $admin->roleModel();
        $this->assertNotNull($role);
        $role->permissions()->detach();

        Sanctum::actingAs($admin, ['staff']);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonFragment(['fulfilment.pick'])
            ->assertJsonFragment(['roles.view']);
    }

    public function test_cashier_cannot_refund_via_gate(): void
    {
        $cashier = User::factory()->cashier()->create();
        Sanctum::actingAs($cashier, ['staff']);

        $this->assertFalse($cashier->can('sales.refund'));
    }

    public function test_store_manager_cannot_view_unassigned_store(): void
    {
        $organization = Organization::factory()->create();
        $storeA = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Store A']);
        $storeB = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Store B']);

        $manager = User::factory()->storeManager()->create();
        $manager->stores()->attach($storeA->id, ['is_default' => true]);

        Sanctum::actingAs($manager, ['staff']);

        $this->getJson("/api/v1/stores/{$storeA->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $storeA->id);

        $this->getJson("/api/v1/stores/{$storeB->id}")
            ->assertForbidden();

        $this->getJson('/api/v1/stores')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $storeA->id);
    }

    public function test_org_admin_can_assign_role_and_store_access(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        Sanctum::actingAs($admin, ['staff']);

        $organization = Organization::factory()->create();
        Organization::query()->whereKeyNot($organization->id)->delete();
        $store = Store::factory()->create(['organization_id' => $organization->id]);

        $create = $this->postJson('/api/v1/users', [
            'name' => 'Sam Cashier',
            'email' => 'sam@example.com',
            'password' => 'password123',
            'role' => 'cashier',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.role', 'cashier');

        $userId = $create->json('data.id');

        $this->patchJson("/api/v1/users/{$userId}/roles", [
            'role' => 'store_manager',
        ])
            ->assertOk()
            ->assertJsonPath('data.role', 'store_manager');

        $this->patchJson("/api/v1/users/{$userId}", [
            'name' => 'Sam Manager',
            'email' => 'sam.manager@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sam Manager')
            ->assertJsonPath('data.email', 'sam.manager@example.com');

        $this->patchJson("/api/v1/users/{$userId}", [
            'password' => 'newpassword123',
        ])->assertOk();

        $this->patchJson("/api/v1/users/{$userId}/stores", [
            'storeIds' => [$store->id],
            'defaultStoreId' => $store->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.storeAccess.0.id', $store->id)
            ->assertJsonPath('data.storeAccess.0.isDefault', true);

        $this->assertTrue(
            User::query()->findOrFail($userId)->canAccessStore($store->id)
        );
    }

    public function test_cashier_cannot_manage_users(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', [
            'name' => 'Nope',
            'email' => 'nope@example.com',
            'password' => 'password123',
            'role' => 'cashier',
        ])->assertForbidden();
    }

    public function test_roles_and_permissions_endpoints_return_seeded_matrix(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonFragment(['code' => 'cashier'])
            ->assertJsonFragment(['code' => 'store_manager']);

        $permissions = $this->getJson('/api/v1/permissions')
            ->assertOk()
            ->json('data');

        $names = collect($permissions)->pluck('name');
        $this->assertTrue($names->contains('sales.refund'));
        $this->assertTrue($names->contains('users.assign-stores'));
    }

    public function test_me_includes_permissions_and_store_access(): void
    {
        $organization = Organization::factory()->create();
        $store = Store::factory()->create(['organization_id' => $organization->id]);
        $manager = User::factory()->storeManager()->create();
        $manager->stores()->attach($store->id, ['is_default' => true]);

        Sanctum::actingAs($manager, ['staff']);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'store_manager')
            ->assertJsonFragment(['sales.refund'])
            ->assertJsonPath('data.storeAccess.0.id', $store->id);
    }
}
