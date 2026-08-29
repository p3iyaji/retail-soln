<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Products\Models\Product;
use Tests\TestCase;

/**
 * `Gate::before` short-circuits every dotted ability with the permission matrix.
 * Because a non-null return skips policies entirely, dotted abilities must never
 * be registered as policy methods, and the hook must fail closed for callers with
 * no role. These cases lock that invariant.
 */
class PermissionGateInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
    }

    public function test_dotted_ability_is_granted_only_by_the_permission_matrix(): void
    {
        $inventoryManager = User::factory()->inventoryManager()->create();
        $cashier = User::factory()->cashier()->create();

        $this->assertTrue(Gate::forUser($inventoryManager)->allows('inventory.adjust'));
        $this->assertFalse(Gate::forUser($cashier)->allows('inventory.adjust'));
    }

    public function test_dotted_ability_fails_closed_for_unknown_and_missing_roles(): void
    {
        $roleless = User::factory()->create(['role' => '']);
        $unknownRole = User::factory()->create(['role' => 'not_a_real_role']);

        $this->assertFalse(Gate::forUser($roleless)->allows('inventory.adjust'));
        $this->assertFalse(Gate::forUser($unknownRole)->allows('inventory.adjust'));
        $this->assertFalse(Gate::forUser($unknownRole)->allows('products.view'));
    }

    public function test_undotted_policy_abilities_still_reach_their_policy(): void
    {
        $inventoryManager = User::factory()->inventoryManager()->create();
        $cashier = User::factory()->cashier()->create();

        $this->assertTrue(Gate::forUser($inventoryManager)->allows('create', Product::class));
        $this->assertFalse(Gate::forUser($cashier)->allows('create', Product::class));
        $this->assertTrue(Gate::forUser($cashier)->allows('viewAny', Product::class));
    }

    /**
     * A dotted `Gate::define` would silently never run, so anything needing extra
     * checks (ownership, store scope) must not be expressed as a dotted ability.
     */
    public function test_matrix_overrides_any_dotted_gate_definition(): void
    {
        Gate::define('inventory.adjust', fn () => true);

        $this->assertFalse(Gate::forUser(User::factory()->cashier()->create())->allows('inventory.adjust'));
    }
}
