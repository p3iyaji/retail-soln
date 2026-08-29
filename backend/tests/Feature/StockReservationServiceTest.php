<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\BalanceBucket;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Inventory\Services\StockReservationService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use RuntimeException;
use Tests\TestCase;

class StockReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockReservationService $reservations;

    private InventoryLedgerService $ledger;

    private int $orgId;

    private Store $store;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reservations = app(StockReservationService::class);
        $this->ledger = app(InventoryLedgerService::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create(['organization_id' => $this->orgId]);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'RSV-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'RSV-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Purchase,
            quantity: '20.0000',
        ));
    }

    public function test_reserve_moves_available_to_reserved(): void
    {
        $this->reservations->reserve([
            'organizationId' => $this->orgId,
            'locationId' => $this->store->id,
            'lines' => [
                ['productVariantId' => $this->variant->id, 'quantity' => '3.0000'],
            ],
            'referenceType' => 'portal_order',
            'referenceId' => 42,
        ]);

        $balance = $this->balance();
        $this->assertSame('17.0000', $balance->available);
        $this->assertSame('3.0000', $balance->reserved);

        $this->assertSame(1, InventoryMovement::query()
            ->where('type', MovementType::Reserve->value)
            ->where('bucket', BalanceBucket::Available->value)
            ->where('quantity', '-3.0000')
            ->count());
        $this->assertSame(1, InventoryMovement::query()
            ->where('type', MovementType::Reserve->value)
            ->where('bucket', BalanceBucket::Reserved->value)
            ->where('quantity', '3.0000')
            ->count());
    }

    public function test_reserve_rejects_insufficient_available(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient available stock');

        $this->reservations->reserve([
            'organizationId' => $this->orgId,
            'locationId' => $this->store->id,
            'lines' => [
                ['productVariantId' => $this->variant->id, 'quantity' => '25.0000'],
            ],
            'referenceType' => 'portal_order',
            'referenceId' => 43,
        ]);
    }

    public function test_reserve_is_idempotent_for_same_reference(): void
    {
        $payload = [
            'organizationId' => $this->orgId,
            'locationId' => $this->store->id,
            'lines' => [
                ['productVariantId' => $this->variant->id, 'quantity' => '2.0000'],
            ],
            'referenceType' => 'portal_order',
            'referenceId' => 44,
        ];

        $this->reservations->reserve($payload);
        $this->reservations->reserve($payload);

        $balance = $this->balance();
        $this->assertSame('18.0000', $balance->available);
        $this->assertSame('2.0000', $balance->reserved);
        $this->assertSame(2, InventoryMovement::query()
            ->where('type', MovementType::Reserve->value)
            ->where('reference_type', 'portal_order')
            ->where('reference_id', 44)
            ->count());
    }

    public function test_release_returns_reserved_to_available_and_is_idempotent(): void
    {
        $payload = [
            'organizationId' => $this->orgId,
            'locationId' => $this->store->id,
            'lines' => [
                ['productVariantId' => $this->variant->id, 'quantity' => '4.0000'],
            ],
            'referenceType' => 'portal_order',
            'referenceId' => 45,
        ];

        $this->reservations->reserve($payload);
        $this->reservations->release($payload);
        $this->reservations->release($payload);

        $balance = $this->balance();
        $this->assertSame('20.0000', $balance->available);
        $this->assertSame('0.0000', $balance->reserved);
        $this->assertSame(2, InventoryMovement::query()
            ->where('type', MovementType::Release->value)
            ->where('reference_id', 45)
            ->count());
    }

    public function test_consume_decrements_reserved_only_and_is_idempotent(): void
    {
        $payload = [
            'organizationId' => $this->orgId,
            'locationId' => $this->store->id,
            'lines' => [
                ['productVariantId' => $this->variant->id, 'quantity' => '5.0000'],
            ],
            'referenceType' => 'portal_order',
            'referenceId' => 47,
        ];

        $this->reservations->reserve($payload);
        $this->reservations->consume($payload);
        $this->reservations->consume($payload);

        $balance = $this->balance();
        $this->assertSame('15.0000', $balance->available);
        $this->assertSame('0.0000', $balance->reserved);
        $this->assertSame(1, InventoryMovement::query()
            ->where('type', MovementType::Sale->value)
            ->where('bucket', BalanceBucket::Reserved->value)
            ->where('reference_id', 47)
            ->where('quantity', '-5.0000')
            ->count());
    }

    public function test_consume_rejects_insufficient_reserved(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient reserved stock to consume');

        $this->reservations->consume([
            'organizationId' => $this->orgId,
            'locationId' => $this->store->id,
            'lines' => [
                ['productVariantId' => $this->variant->id, 'quantity' => '1.0000'],
            ],
            'referenceType' => 'portal_order',
            'referenceId' => 48,
        ]);
    }

    public function test_consume_rejects_empty_lines_and_invalid_variant_or_qty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reservation requires at least one line');
        $this->reservations->consume([
            'organizationId' => $this->orgId,
            'locationId' => $this->store->id,
            'lines' => [],
            'referenceType' => 'portal_order',
            'referenceId' => 49,
        ]);
    }

    public function test_consume_rejects_null_variant_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reservation product variant is required');

        $this->reservations->consume([
            'organizationId' => $this->orgId,
            'locationId' => $this->store->id,
            'lines' => [
                ['productVariantId' => null, 'quantity' => '1.0000'],
            ],
            'referenceType' => 'portal_order',
            'referenceId' => 50,
        ]);
    }

    public function test_consume_rejects_non_positive_qty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reservation quantity must be a positive decimal');

        $this->reservations->consume([
            'organizationId' => $this->orgId,
            'locationId' => $this->store->id,
            'lines' => [
                ['productVariantId' => $this->variant->id, 'quantity' => '0'],
            ],
            'referenceType' => 'portal_order',
            'referenceId' => 51,
        ]);
    }

    public function test_reserve_completes_missing_pair_leg(): void
    {
        $refId = 46;
        $availableUuid = \Ramsey\Uuid\Uuid::uuid5(
            \Ramsey\Uuid\Uuid::NAMESPACE_URL,
            sprintf('portal_order:%d:reserve:%d:available', $refId, $this->variant->id),
        )->toString();

        $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Reserve,
            quantity: '-1.0000',
            bucket: BalanceBucket::Available,
            referenceType: 'portal_order',
            referenceId: $refId,
            uuid: $availableUuid,
        ));

        $this->reservations->reserve([
            'organizationId' => $this->orgId,
            'locationId' => $this->store->id,
            'lines' => [
                ['productVariantId' => $this->variant->id, 'quantity' => '1.0000'],
            ],
            'referenceType' => 'portal_order',
            'referenceId' => $refId,
        ]);

        $balance = $this->balance();
        $this->assertSame('19.0000', $balance->available);
        $this->assertSame('1.0000', $balance->reserved);
        $this->assertSame(2, InventoryMovement::query()
            ->where('type', MovementType::Reserve->value)
            ->where('reference_id', $refId)
            ->count());
    }

    private function balance(): InventoryBalance
    {
        return InventoryBalance::query()
            ->where('organization_id', $this->orgId)
            ->where('location_type', LocationType::Store->value)
            ->where('location_id', $this->store->id)
            ->where('product_variant_id', $this->variant->id)
            ->firstOrFail();
    }
}
