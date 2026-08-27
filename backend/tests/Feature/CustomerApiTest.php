<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Audit\Models\AuditLog;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Customers\Models\Customer;
use Modules\Organization\Services\OrganizationService;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
    }

    public function test_walk_in_is_available_via_list_and_walk_in_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());

        $list = $this->getJson('/api/v1/customers')->assertOk();
        $this->assertTrue(
            collect($list->json('data'))->contains(fn (array $c) => ($c['isWalkIn'] ?? false) === true)
        );

        $this->getJson('/api/v1/customers/walk-in')
            ->assertOk()
            ->assertJsonPath('data.isWalkIn', true)
            ->assertJsonPath('data.name', 'Walk-in');

        $orgId = app(OrganizationService::class)->current()->id;
        $this->assertSame(
            1,
            Customer::query()->where('organization_id', $orgId)->where('is_walk_in', true)->count()
        );
    }

    public function test_search_matches_name_phone_email_and_account_number(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());

        $created = $this->postJson('/api/v1/customers', [
            'name' => 'Ada Lovelace',
            'phone' => '+15551234567',
            'email' => 'ada@example.com',
            'accountNumber' => 'ACC-42',
        ])->assertCreated();

        $id = $created->json('data.id');

        foreach (['Ada', '555123', 'ada@example', 'ACC-42'] as $q) {
            $this->getJson('/api/v1/customers?q='.urlencode($q))
                ->assertOk()
                ->assertJsonFragment(['id' => $id]);
        }

        $this->getJson('/api/v1/customers?q=no-such-customer')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_create_registered_customer_with_addresses(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());

        $group = $this->postJson('/api/v1/customer-groups', [
            'name' => 'VIP',
            'code' => 'VIP',
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/customers', [
            'name' => 'Grace Hopper',
            'type' => 'registered',
            'phone' => '555-0100',
            'customerGroupId' => $group['id'],
            'creditLimit' => '1000.0000',
            'addresses' => [
                [
                    'type' => 'billing',
                    'line1' => '1 Navy Yard',
                    'city' => 'Arlington',
                    'isDefault' => true,
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Grace Hopper')
            ->assertJsonPath('data.isWalkIn', false)
            ->assertJsonPath('data.creditLimit', '1000.0000')
            ->assertJsonPath('data.customerGroupId', $group['id'])
            ->assertJsonPath('data.addresses.0.line1', '1 Navy Yard');
    }

    public function test_history_returns_empty_sections_when_authorized(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());

        $customerId = $this->postJson('/api/v1/customers', [
            'name' => 'History Subject',
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/customers/{$customerId}/history")
            ->assertOk()
            ->assertJsonPath('data.sales', [])
            ->assertJsonPath('data.invoices', [])
            ->assertJsonPath('data.payments', [])
            ->assertJsonPath('data.returns', [])
            ->assertJsonPath('data.installmentPlans', []);
    }

    public function test_history_forbidden_without_view_permission(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());
        $customerId = $this->postJson('/api/v1/customers', [
            'name' => 'Secret Customer',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs(User::factory()->create(['role' => 'delivery_staff']));

        $this->getJson("/api/v1/customers/{$customerId}/history")->assertForbidden();
        $this->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_cannot_delete_walk_in_customer(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());

        $walkInId = $this->getJson('/api/v1/customers/walk-in')
            ->assertOk()
            ->json('data.id');

        $this->deleteJson("/api/v1/customers/{$walkInId}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete the Walk-in customer.');

        $this->assertDatabaseHas('customers', [
            'id' => $walkInId,
            'is_walk_in' => true,
            'deleted_at' => null,
        ]);
    }

    public function test_manage_forbidden_without_manage_permission(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']));

        $this->getJson('/api/v1/customers')->assertOk();

        $this->postJson('/api/v1/customers', [
            'name' => 'Blocked Create',
        ])->assertForbidden();

        Sanctum::actingAs(User::factory()->orgAdmin()->create());
        $customerId = $this->postJson('/api/v1/customers', [
            'name' => 'Editable',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']));

        $this->patchJson("/api/v1/customers/{$customerId}", [
            'name' => 'Hacked',
        ])->assertForbidden();

        $this->deleteJson("/api/v1/customers/{$customerId}")->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
        $this->postJson('/api/v1/customers', ['name' => 'X'])->assertUnauthorized();
    }

    public function test_soft_deleted_customers_are_excluded_from_search(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());

        $id = $this->postJson('/api/v1/customers', [
            'name' => 'Gone Customer',
            'phone' => '999-gone',
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/customers/{$id}")->assertNoContent();

        $this->getJson('/api/v1/customers?q=Gone')
            ->assertOk()
            ->assertJsonMissing(['id' => $id]);
    }

    public function test_account_number_auto_generated_when_omitted(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());

        $data = $this->postJson('/api/v1/customers', [
            'name' => 'Auto Account',
        ])->assertCreated()->json('data');

        $this->assertSame('C-'.$data['id'], $data['accountNumber']);
    }

    public function test_cannot_patch_walk_in_rename_type_or_deactivate(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());

        $walkInId = $this->getJson('/api/v1/customers/walk-in')
            ->assertOk()
            ->json('data.id');

        $this->patchJson("/api/v1/customers/{$walkInId}", [
            'name' => 'Renamed Walk-in',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot rename the Walk-in customer.');

        $this->patchJson("/api/v1/customers/{$walkInId}", [
            'type' => 'registered',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change Walk-in customer type.');

        $this->patchJson("/api/v1/customers/{$walkInId}", [
            'isActive' => false,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot deactivate the Walk-in customer.');

        $this->patchJson("/api/v1/customers/{$walkInId}", [
            'accountNumber' => 'CHANGED',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change Walk-in account number.');
    }

    public function test_cannot_convert_registered_customer_to_walk_in(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());

        $customerId = $this->postJson('/api/v1/customers', [
            'name' => 'Registered Only',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/customers/{$customerId}", [
            'type' => 'walk_in',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot convert a customer to Walk-in.');
    }

    public function test_can_patch_registered_customer_fields_and_addresses(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());

        $customerId = $this->postJson('/api/v1/customers', [
            'name' => 'Before Update',
            'phone' => '111',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/customers/{$customerId}", [
            'name' => 'After Update',
            'phone' => '222-3333',
            'email' => 'after@example.com',
            'creditLimit' => '250.5000',
            'addresses' => [
                [
                    'type' => 'shipping',
                    'line1' => '9 Harbor Rd',
                    'line2' => 'Suite 2',
                    'city' => 'Lagos',
                    'region' => 'LA',
                    'postalCode' => '100001',
                    'country' => 'NG',
                    'isDefault' => true,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'After Update')
            ->assertJsonPath('data.phone', '222-3333')
            ->assertJsonPath('data.email', 'after@example.com')
            ->assertJsonPath('data.creditLimit', '250.5000')
            ->assertJsonPath('data.addresses.0.line1', '9 Harbor Rd')
            ->assertJsonPath('data.addresses.0.line2', 'Suite 2')
            ->assertJsonPath('data.addresses.0.postalCode', '100001');
    }

    public function test_can_show_customer(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());

        $customerId = $this->postJson('/api/v1/customers', [
            'name' => 'Show Me',
            'phone' => '555-SHOW',
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/customers/{$customerId}")
            ->assertOk()
            ->assertJsonPath('data.id', $customerId)
            ->assertJsonPath('data.name', 'Show Me')
            ->assertJsonPath('data.phone', '555-SHOW');
    }

    public function test_group_create_forbidden_without_manage(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']));

        $this->postJson('/api/v1/customer-groups', [
            'name' => 'Blocked Group',
        ])->assertForbidden();
    }

    public function test_create_writes_audit_log(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create());

        $customerId = $this->postJson('/api/v1/customers', [
            'name' => 'Audited Customer',
        ])->assertCreated()->json('data.id');

        $this->assertTrue(
            AuditLog::query()
                ->where('auditable_type', Customer::class)
                ->where('auditable_id', $customerId)
                ->where('action', 'created')
                ->exists()
        );
    }
}
