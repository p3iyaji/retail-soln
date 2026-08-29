<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Sync\Models\Device;
use Tests\TestCase;

class DeviceApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private User $manager;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(PermissionMatrixSeeder::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create([
            'organization_id' => $this->orgId,
        ]);

        $this->manager = User::factory()->storeManager()->create();
        $this->manager->stores()->attach($this->store->id, ['is_default' => true]);

        $this->cashier = User::factory()->cashier()->create();
        $this->cashier->stores()->attach($this->store->id, ['is_default' => true]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Store-Id' => (string) $this->store->id];
    }

    public function test_manager_can_register_list_rename_and_disable_device(): void
    {
        Sanctum::actingAs($this->manager);
        $uuid = '550e8400-e29b-41d4-a716-446655440701';

        $create = $this->postJson('/api/v1/devices', [
            'uuid' => strtoupper($uuid),
            'name' => 'Front counter',
        ], $this->headers());

        $create->assertCreated()
            ->assertJsonPath('data.uuid', $uuid)
            ->assertJsonPath('data.name', 'Front counter')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.storeId', $this->store->id);

        $deviceId = (int) $create->json('data.id');

        $this->getJson('/api/v1/devices', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $uuid)
            ->assertJsonPath('meta.total', 1);

        $this->patchJson('/api/v1/devices/'.$deviceId, [
            'name' => 'Back counter',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.name', 'Back counter')
            ->assertJsonPath('data.enabled', true);

        $this->patchJson('/api/v1/devices/'.$deviceId, [
            'enabled' => false,
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.name', 'Back counter');

        $this->assertDatabaseHas('devices', [
            'id' => $deviceId,
            'uuid' => $uuid,
            'name' => 'Back counter',
            'enabled' => false,
            'store_id' => $this->store->id,
        ]);
    }

    public function test_duplicate_uuid_returns_validation_422(): void
    {
        Sanctum::actingAs($this->manager);
        $uuid = '550e8400-e29b-41d4-a716-446655440702';

        Device::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'uuid' => $uuid,
            'name' => 'Existing',
            'enabled' => true,
        ]);

        $this->postJson('/api/v1/devices', [
            'uuid' => $uuid,
            'name' => 'Duplicate',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['uuid']);
    }

    public function test_missing_store_header_returns_422(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/v1/devices', [
            'uuid' => '550e8400-e29b-41d4-a716-44665544070a',
            'name' => 'No store',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'X-Store-Id header is required.');
    }

    public function test_cashier_without_devices_manage_is_forbidden(): void
    {
        Sanctum::actingAs($this->cashier);

        $device = Device::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'uuid' => '550e8400-e29b-41d4-a716-446655440703',
            'name' => 'Existing',
            'enabled' => true,
        ]);

        $this->getJson('/api/v1/devices', $this->headers())
            ->assertForbidden();

        $this->postJson('/api/v1/devices', [
            'uuid' => '550e8400-e29b-41d4-a716-44665544070b',
            'name' => 'Nope',
        ], $this->headers())
            ->assertForbidden();

        $this->patchJson('/api/v1/devices/'.$device->id, [
            'enabled' => false,
        ], $this->headers())
            ->assertForbidden();
    }

    public function test_registered_device_reports_enabled_on_sync_status(): void
    {
        Sanctum::actingAs($this->manager);
        $uuid = '550e8400-e29b-41d4-a716-446655440704';

        $this->postJson('/api/v1/devices', [
            'uuid' => $uuid,
            'name' => 'Ready POS',
        ], $this->headers())->assertCreated();

        Sanctum::actingAs($this->cashier);
        $this->getJson('/api/v1/sync/status?deviceUuid='.$uuid, $this->headers())
            ->assertOk()
            ->assertJsonPath('data.deviceEnabled', true);
    }
}
