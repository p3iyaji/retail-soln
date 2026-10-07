<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Attendance\Models\ClockLocation;
use Modules\Attendance\Models\ClockLocationAssignment;
use Modules\Attendance\Models\ClockShift;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Tests\TestCase;

class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private User $admin;

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

        $this->admin = User::factory()->orgAdmin()->create();
        $this->manager = User::factory()->storeManager()->create();
        $this->manager->stores()->attach($this->store->id, ['is_default' => true]);
        $this->cashier = User::factory()->cashier()->create();
        $this->cashier->stores()->attach($this->store->id, ['is_default' => true]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_assigned_clock_in_opens_shift_with_snapshotted_rate(): void
    {
        $locationId = $this->createLocation($this->admin, [
            'name' => 'Front counter',
            'storeId' => $this->store->id,
        ]);
        $this->assign($this->admin, $locationId, $this->cashier->id, '15.5000');

        Sanctum::actingAs($this->cashier, ['staff']);
        $created = $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertCreated();
        $this->assertSame('15.5000', $created->json('data.hourlyRate'));
        $this->assertNull($created->json('data.clockOutAt'));
        $this->assertNull($created->json('data.grossPay'));
        $this->assertSame($locationId, $created->json('data.locationId'));
        $this->assertSame($this->cashier->id, $created->json('data.userId'));

        $locations = $this->getJson('/api/v1/attendance/locations')->assertOk();
        $this->assertSame('Front counter', $locations->json('data.0.name'));
        $this->assertSame('15.5000', $locations->json('data.0.myAssignment.hourlyRate'));
        $this->assertSame([], $locations->json('data.0.assignments'));

        $this->assertDatabaseCount('clock_shifts', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'auditable_type' => ClockLocation::class,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'auditable_type' => ClockLocationAssignment::class,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'clock-in',
            'auditable_type' => ClockShift::class,
            'user_id' => $this->cashier->id,
        ]);
    }

    public function test_unassigned_or_inactive_clock_in_returns_422(): void
    {
        $locationId = $this->createLocation($this->admin, [
            'name' => 'Front counter',
            'storeId' => $this->store->id,
        ]);

        Sanctum::actingAs($this->cashier, ['staff']);
        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertStatus(422);
        $this->assertDatabaseCount('clock_shifts', 0);

        $this->assign($this->admin, $locationId, $this->cashier->id, '15.5000');
        Sanctum::actingAs($this->admin, ['staff']);
        $this->patchJson('/api/v1/attendance/locations/'.$locationId, [
            'isActive' => false,
        ])->assertOk();

        Sanctum::actingAs($this->cashier, ['staff']);
        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertStatus(422);
        $this->assertDatabaseCount('clock_shifts', 0);

        Sanctum::actingAs($this->admin, ['staff']);
        $this->patchJson('/api/v1/attendance/locations/'.$locationId, [
            'isActive' => true,
        ])->assertOk();
        $this->assign($this->admin, $locationId, $this->cashier->id, '15.5000', false);

        Sanctum::actingAs($this->cashier, ['staff']);
        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertStatus(422);
        $this->assertDatabaseCount('clock_shifts', 0);
    }

    public function test_clock_in_outside_geofence_or_missing_coordinates_returns_422(): void
    {
        $locationId = $this->createLocation($this->admin, [
            'name' => 'Gated door',
            'storeId' => $this->store->id,
            'latitude' => 0,
            'longitude' => 0,
            'radiusMeters' => 100,
        ]);
        $this->assign($this->admin, $locationId, $this->cashier->id, '15.5000');

        Sanctum::actingAs($this->cashier, ['staff']);
        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertStatus(422);

        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
            'latitude' => 0.002,
            'longitude' => 0,
        ])->assertStatus(422);
        $this->assertDatabaseCount('clock_shifts', 0);

        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
            'latitude' => 0.0001,
            'longitude' => 0,
        ])->assertCreated();
        $this->assertDatabaseCount('clock_shifts', 1);
    }

    public function test_clock_in_when_already_in_returns_422(): void
    {
        $locationId = $this->createLocation($this->admin, [
            'name' => 'Front counter',
            'storeId' => $this->store->id,
        ]);
        $this->assign($this->admin, $locationId, $this->cashier->id, '15.5000');

        Sanctum::actingAs($this->cashier, ['staff']);
        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertCreated();

        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertStatus(422);
        $this->assertDatabaseCount('clock_shifts', 1);
    }

    public function test_clock_out_calculates_duration_and_gross_pay(): void
    {
        $locationId = $this->createLocation($this->admin, [
            'name' => 'Front counter',
            'storeId' => $this->store->id,
        ]);
        $this->assign($this->admin, $locationId, $this->cashier->id, '15.5000');

        $start = now()->startOfSecond();
        $this->travelTo($start);

        Sanctum::actingAs($this->cashier, ['staff']);
        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertCreated();

        $this->travelTo($start->copy()->addMinutes(90));

        $closed = $this->postJson('/api/v1/attendance/clock-out')->assertOk();
        $this->assertSame(90, $closed->json('data.durationMinutes'));
        $this->assertSame('23.2500', $closed->json('data.grossPay'));
        $this->assertSame('15.5000', $closed->json('data.hourlyRate'));

        $summary = $this->getJson('/api/v1/attendance/summary')->assertOk();
        $this->assertSame(90, $summary->json('data.durationMinutes'));
        $this->assertSame('1.5000', $summary->json('data.hours'));
        $this->assertSame('23.2500', $summary->json('data.grossPay'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'clock-out',
            'auditable_type' => ClockShift::class,
            'user_id' => $this->cashier->id,
        ]);

        $this->travelBack();
    }

    public function test_clock_out_without_open_shift_returns_422(): void
    {
        Sanctum::actingAs($this->cashier, ['staff']);
        $this->postJson('/api/v1/attendance/clock-out')->assertStatus(422);
        $this->assertDatabaseCount('clock_shifts', 0);
    }

    public function test_forbidden_without_clock_permission_or_when_store_manager_touches_another_store(): void
    {
        $roleless = User::factory()->create(['role' => '']);
        Sanctum::actingAs($roleless, ['staff']);
        $this->postJson('/api/v1/attendance/clock-in', [])->assertForbidden();
        $this->assertDatabaseCount('clock_shifts', 0);

        $other = Store::factory()->create([
            'organization_id' => $this->orgId,
        ]);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->postJson('/api/v1/attendance/locations', [
            'name' => 'Other counter',
            'storeId' => $other->id,
        ])->assertForbidden();
        $this->postJson('/api/v1/attendance/locations', [
            'name' => 'Head office',
        ])->assertForbidden();

        $locationId = $this->createLocation($this->admin, [
            'name' => 'Other counter',
            'storeId' => $other->id,
        ]);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->patchJson('/api/v1/attendance/locations/'.$locationId, [
            'name' => 'Taken',
        ])->assertForbidden();
        $this->postJson('/api/v1/attendance/locations/'.$locationId.'/assignments', [
            'userId' => $this->cashier->id,
            'hourlyRate' => '10.0000',
        ])->assertForbidden();

        $this->assertDatabaseMissing('clock_locations', [
            'name' => 'Taken',
        ]);
        $this->assertDatabaseMissing('clock_locations', [
            'name' => 'Head office',
        ]);
    }

    public function test_void_sets_voided_at_excludes_pay_and_forbids_without_manage(): void
    {
        $locationId = $this->createLocation($this->manager, [
            'name' => 'Front counter',
            'storeId' => $this->store->id,
        ]);
        $this->assign($this->manager, $locationId, $this->cashier->id, '15.5000');

        $start = now()->startOfSecond();
        $this->travelTo($start);
        Sanctum::actingAs($this->cashier, ['staff']);
        $clockIn = $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertCreated();
        $shiftId = (int) $clockIn->json('data.id');

        $this->travelTo($start->copy()->addMinutes(90));
        $this->postJson('/api/v1/attendance/clock-out')->assertOk();

        Sanctum::actingAs($this->manager, ['staff']);
        $beforeVoid = $this->getJson('/api/v1/attendance/summary')->assertOk();
        $this->assertSame('23.2500', $beforeVoid->json('data.grossPay'));
        $this->assertSame(90, $beforeVoid->json('data.durationMinutes'));

        Sanctum::actingAs($this->cashier, ['staff']);
        $this->postJson('/api/v1/attendance/shifts/'.$shiftId.'/void')->assertForbidden();
        $this->assertNull(ClockShift::query()->find($shiftId)?->voided_at);

        Sanctum::actingAs($this->manager, ['staff']);
        $voided = $this->postJson('/api/v1/attendance/shifts/'.$shiftId.'/void')->assertOk();
        $this->assertNotNull($voided->json('data.voidedAt'));
        $this->assertNotNull(ClockShift::query()->find($shiftId)?->voided_at);

        $afterVoid = $this->getJson('/api/v1/attendance/summary')->assertOk();
        $this->assertSame('0.0000', $afterVoid->json('data.grossPay'));
        $this->assertSame(0, $afterVoid->json('data.durationMinutes'));
        $this->assertSame('0.0000', $afterVoid->json('data.hours'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'voided',
            'auditable_type' => ClockShift::class,
            'auditable_id' => $shiftId,
        ]);

        $this->travelBack();
    }

    public function test_summary_while_shift_is_open_is_zero(): void
    {
        $locationId = $this->createLocation($this->admin, [
            'name' => 'Front counter',
            'storeId' => $this->store->id,
        ]);
        $this->assign($this->admin, $locationId, $this->cashier->id, '15.5000');

        Sanctum::actingAs($this->cashier, ['staff']);
        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertCreated();

        $summary = $this->getJson('/api/v1/attendance/summary')->assertOk();
        $this->assertSame(0, $summary->json('data.durationMinutes'));
        $this->assertSame('0.0000', $summary->json('data.grossPay'));
    }

    public function test_later_rate_change_does_not_rewrite_closed_shift_pay(): void
    {
        $locationId = $this->createLocation($this->admin, [
            'name' => 'Front counter',
            'storeId' => $this->store->id,
        ]);
        $this->assign($this->admin, $locationId, $this->cashier->id, '15.5000');

        $start = now()->startOfSecond();
        $this->travelTo($start);

        Sanctum::actingAs($this->cashier, ['staff']);
        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertCreated();

        $this->assign($this->admin, $locationId, $this->cashier->id, '99.0000');

        $this->travelTo($start->copy()->addMinutes(90));
        Sanctum::actingAs($this->cashier, ['staff']);
        $closed = $this->postJson('/api/v1/attendance/clock-out')->assertOk();
        $this->assertSame('15.5000', $closed->json('data.hourlyRate'));
        $this->assertSame('23.2500', $closed->json('data.grossPay'));

        $this->travelBack();
    }

    public function test_location_with_coordinates_but_no_radius_accepts_clock_in_without_coordinates(): void
    {
        $locationId = $this->createLocation($this->admin, [
            'name' => 'Unfenced door',
            'storeId' => $this->store->id,
            'latitude' => 0,
            'longitude' => 0,
        ]);
        $this->assign($this->admin, $locationId, $this->cashier->id, '15.5000');

        Sanctum::actingAs($this->cashier, ['staff']);
        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertCreated()
            ->assertJsonPath('data.locationId', $locationId);
    }

    public function test_store_manager_cannot_void_shift_at_another_store(): void
    {
        $other = Store::factory()->create([
            'organization_id' => $this->orgId,
        ]);
        $locationId = $this->createLocation($this->admin, [
            'name' => 'Other counter',
            'storeId' => $other->id,
        ]);
        $this->assign($this->admin, $locationId, $this->cashier->id, '15.5000');

        Sanctum::actingAs($this->cashier, ['staff']);
        $shiftId = (int) $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->postJson('/api/v1/attendance/shifts/'.$shiftId.'/void')->assertForbidden();
        $this->assertNull(ClockShift::query()->find($shiftId)?->voided_at);
    }

    public function test_cashier_shift_list_excludes_other_users(): void
    {
        $other = User::factory()->cashier()->create();
        $locationId = $this->createLocation($this->admin, [
            'name' => 'Front counter',
            'storeId' => $this->store->id,
        ]);
        $this->assign($this->admin, $locationId, $this->cashier->id, '15.5000');
        $this->assign($this->admin, $locationId, $other->id, '12.0000');

        Sanctum::actingAs($other, ['staff']);
        $otherShiftId = (int) $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->cashier, ['staff']);
        $this->postJson('/api/v1/attendance/clock-in', [
            'locationId' => $locationId,
        ])->assertCreated();

        $listed = $this->getJson('/api/v1/attendance/shifts')->assertOk();
        $userIds = collect($listed->json('data'))->pluck('userId')->all();
        $shiftIds = collect($listed->json('data'))->pluck('id')->all();

        $this->assertContains($this->cashier->id, $userIds);
        $this->assertNotContains($other->id, $userIds);
        $this->assertNotContains($otherShiftId, $shiftIds);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createLocation(User $actor, array $payload): int
    {
        Sanctum::actingAs($actor, ['staff']);

        $response = $this->postJson('/api/v1/attendance/locations', $payload)->assertCreated();

        return (int) $response->json('data.id');
    }

    private function assign(User $actor, int $locationId, int $userId, string $rate, bool $active = true): void
    {
        Sanctum::actingAs($actor, ['staff']);

        $this->postJson('/api/v1/attendance/locations/'.$locationId.'/assignments', [
            'userId' => $userId,
            'hourlyRate' => $rate,
            'isActive' => $active,
        ])->assertOk()
            ->assertJsonPath('data.hourlyRate', $active ? bcadd($rate, '0', 4) : bcadd($rate, '0', 4));
    }
}
