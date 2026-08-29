<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Enums\CashierSessionStatus;
use Modules\Sales\Enums\CashMovementType;
use Modules\Sales\Models\CashMovement;
use Tests\TestCase;

class CashierSessionApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create(['organization_id' => $this->orgId]);
        $this->cashier = User::factory()->cashier()->create();
        $this->cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($this->cashier);
    }

    private function headers(): array
    {
        return ['X-Store-Id' => (string) $this->store->id];
    }

    private function openSession(string $terminal = 'POS-001', string $float = '100.0000'): int
    {
        $response = $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => $terminal,
            'openingFloat' => $float,
        ], $this->headers());

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    public function test_open_session_creates_terminal_and_session(): void
    {
        $sessionId = $this->openSession();

        $this->assertDatabaseHas('terminals', [
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'name' => 'POS-001',
        ]);

        $this->assertDatabaseHas('cashier_sessions', [
            'id' => $sessionId,
            'status' => CashierSessionStatus::Open->value,
            'opening_float' => '100.0000',
            'user_id' => $this->cashier->id,
        ]);
    }

    public function test_cannot_open_second_session_on_same_terminal(): void
    {
        $this->openSession();

        $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => 'POS-001',
            'openingFloat' => '50.0000',
        ], $this->headers())->assertStatus(422);
    }

    public function test_current_session_lookup(): void
    {
        $sessionId = $this->openSession('POS-002');

        $this->getJson('/api/v1/pos/sessions/current?terminalName=POS-002', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId)
            ->assertJsonPath('data.status', 'open');

        $this->getJson('/api/v1/pos/sessions/current?terminalName=POS-999', $this->headers())
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_cash_in_and_out_movements(): void
    {
        $sessionId = $this->openSession();

        $this->postJson("/api/v1/pos/sessions/{$sessionId}/cash-movements", [
            'type' => 'cash_in',
            'amount' => '25.0000',
            'notes' => 'Petty cash top-up',
        ], $this->headers())->assertCreated();

        $this->postJson("/api/v1/pos/sessions/{$sessionId}/cash-movements", [
            'type' => 'cash_out',
            'amount' => '10.0000',
        ], $this->headers())->assertCreated();

        $this->assertSame(2, CashMovement::query()->where('session_id', $sessionId)->count());
    }

    public function test_close_session_computes_variance(): void
    {
        $sessionId = $this->openSession('POS-003', '100.0000');

        $this->postJson("/api/v1/pos/sessions/{$sessionId}/cash-movements", [
            'type' => 'cash_in',
            'amount' => '50.0000',
        ], $this->headers())->assertCreated();

        CashMovement::query()->create([
            'organization_id' => $this->orgId,
            'session_id' => $sessionId,
            'type' => CashMovementType::SaleCash,
            'amount' => '30.0000',
            'user_id' => $this->cashier->id,
            'reference_type' => 'sale',
            'reference_id' => 1,
        ]);

        $response = $this->postJson("/api/v1/pos/sessions/{$sessionId}/close", [
            'closingCash' => '175.0000',
            'notes' => 'Balanced',
        ], $this->headers());

        $response->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.expectedCash', '180.0000')
            ->assertJsonPath('data.closingCash', '175.0000')
            ->assertJsonPath('data.variance', '-5.0000');

        $this->assertDatabaseHas('cashier_sessions', [
            'id' => $sessionId,
            'status' => CashierSessionStatus::Closed->value,
        ]);
    }

    public function test_store_manager_can_list_sessions(): void
    {
        $this->openSession();

        $manager = User::factory()->storeManager()->create();
        $manager->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/pos/sessions', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_user_without_store_access_cannot_list_sessions(): void
    {
        $otherCashier = User::factory()->cashier()->create();
        Sanctum::actingAs($otherCashier);

        $this->getJson('/api/v1/pos/sessions', $this->headers())->assertForbidden();
    }

    public function test_open_session_requires_permission(): void
    {
        $user = User::factory()->create(['role' => 'accountant']);
        $user->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => 'POS-001',
            'openingFloat' => '0.0000',
        ], $this->headers())->assertForbidden();
    }
}
