<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Audit\Models\AuditLog;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Settings\Models\Setting;
use Tests\TestCase;

class SettingsAuditApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
    }

    public function test_org_admin_can_upsert_and_list_settings(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->patchJson('/api/v1/settings', [
            'key' => 'pos.receipt_footer',
            'value' => ['text' => 'Thank you'],
        ])
            ->assertOk()
            ->assertJsonPath('data.key', 'pos.receipt_footer')
            ->assertJsonPath('data.value.text', 'Thank you');

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'pos.receipt_footer');

        $this->assertDatabaseHas('settings', [
            'key' => 'pos.receipt_footer',
        ]);
    }

    public function test_cashier_cannot_manage_settings(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);

        $this->getJson('/api/v1/settings')->assertForbidden();
        $this->patchJson('/api/v1/settings', [
            'key' => 'x',
            'value' => ['a' => 1],
        ])->assertForbidden();
    }

    public function test_sensitive_entity_changes_create_audit_logs(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->postJson('/api/v1/stores', [
            'name' => 'Audited Store',
            'currency' => 'USD',
            'city' => 'Lagos',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'auditable_type' => Store::class,
        ]);

        $store = Store::query()->firstOrFail();

        $this->patchJson("/api/v1/stores/{$store->id}", [
            'name' => 'Audited Store Renamed',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'updated',
            'auditable_type' => Store::class,
            'auditable_id' => $store->id,
        ]);

        $log = AuditLog::query()
            ->where('action', 'updated')
            ->where('auditable_id', $store->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Audited Store', $log->before['name'] ?? null);
        $this->assertSame('Audited Store Renamed', $log->after['name'] ?? null);
        $this->assertNotNull($log->user_id);
    }

    public function test_audit_logs_are_listable_but_not_mutable_via_api(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->patchJson('/api/v1/settings', [
            'key' => 'feature.flag',
            'value' => ['enabled' => true],
        ])->assertOk();

        $list = $this->getJson('/api/v1/audit-logs')->assertOk();
        $this->assertGreaterThanOrEqual(1, $list->json('meta.total'));

        $logId = AuditLog::query()->value('id');

        $this->patchJson("/api/v1/audit-logs/{$logId}", [
            'action' => 'tampered',
        ])->assertStatus(405);

        $this->deleteJson("/api/v1/audit-logs/{$logId}")
            ->assertStatus(405);

        $this->assertDatabaseHas('audit_logs', ['id' => $logId, 'action' => 'created']);
    }

    public function test_audit_log_model_rejects_update_and_delete(): void
    {
        $organization = Organization::factory()->create();
        $log = AuditLog::query()->create([
            'organization_id' => $organization->id,
            'action' => 'created',
            'auditable_type' => Setting::class,
            'auditable_id' => 1,
            'after' => ['key' => 'x'],
            'created_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $log->update(['action' => 'hacked']);
    }

    public function test_cashier_cannot_view_audit_logs(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }
}
