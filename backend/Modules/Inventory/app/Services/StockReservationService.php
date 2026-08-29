<?php

namespace Modules\Inventory\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\BalanceBucket;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryMovement;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Dual-post available↔reserved holds and reserved consume via InventoryLedgerService (AD-2).
 *
 * Portal place-order reserves; cancel / unpaid TTL release; pack-complete consumes
 * reserved (−reserved, Sale). Payment success must not call this service.
 * Do not use POS SaleCompletionService for portal fulfilment.
 */
class StockReservationService
{
    public function __construct(private readonly InventoryLedgerService $ledger) {}

    /**
     * Move available → reserved at a store location.
     *
     * @param  array{
     *   organizationId: int,
     *   locationId: int,
     *   lines: list<array{productVariantId: int, quantity: string}>,
     *   referenceType: string,
     *   referenceId: int,
     *   userId?: ?int,
     *   notes?: ?string,
     * }  $input
     */
    public function reserve(array $input): void
    {
        DB::transaction(function () use ($input): void {
            $needed = $this->aggregateLines($input['lines']);
            $notes = $input['notes'] ?? null;
            $userId = $input['userId'] ?? null;

            foreach ($needed as $variantId => $qty) {
                $availableUuid = $this->movementUuid(
                    $input['referenceType'],
                    $input['referenceId'],
                    $variantId,
                    'reserve',
                    BalanceBucket::Available->value,
                );
                $reservedUuid = $this->movementUuid(
                    $input['referenceType'],
                    $input['referenceId'],
                    $variantId,
                    'reserve',
                    BalanceBucket::Reserved->value,
                );

                $hasAvailable = $this->movementExists($availableUuid);
                $hasReserved = $this->movementExists($reservedUuid);

                if ($hasAvailable && $hasReserved) {
                    continue;
                }

                $lineNote = $notes ?? sprintf(
                    'Reserve for %s #%d',
                    $input['referenceType'],
                    $input['referenceId'],
                );
                $negQty = bcmul($qty, '-1', 4);

                if (! $hasAvailable) {
                    $balance = $this->ledger->getLockedBalance(
                        $input['organizationId'],
                        LocationType::Store,
                        $input['locationId'],
                        $variantId,
                    );

                    if (bccomp((string) $balance->available, $qty, 4) < 0) {
                        throw new RuntimeException('Insufficient available stock for one or more items.');
                    }

                    $this->ledger->post(new PostMovementData(
                        organizationId: $input['organizationId'],
                        locationType: LocationType::Store,
                        locationId: $input['locationId'],
                        productVariantId: $variantId,
                        type: MovementType::Reserve,
                        quantity: $negQty,
                        bucket: BalanceBucket::Available,
                        referenceType: $input['referenceType'],
                        referenceId: $input['referenceId'],
                        userId: $userId,
                        notes: $lineNote,
                        uuid: $availableUuid,
                    ));
                }

                if (! $hasReserved) {
                    $this->ledger->post(new PostMovementData(
                        organizationId: $input['organizationId'],
                        locationType: LocationType::Store,
                        locationId: $input['locationId'],
                        productVariantId: $variantId,
                        type: MovementType::Reserve,
                        quantity: $qty,
                        bucket: BalanceBucket::Reserved,
                        referenceType: $input['referenceType'],
                        referenceId: $input['referenceId'],
                        userId: $userId,
                        notes: $lineNote,
                        uuid: $reservedUuid,
                    ));
                }
            }
        });
    }

    /**
     * Move reserved → available. Idempotent when both release movements already exist.
     *
     * @param  array{
     *   organizationId: int,
     *   locationId: int,
     *   lines: list<array{productVariantId: int, quantity: string}>,
     *   referenceType: string,
     *   referenceId: int,
     *   userId?: ?int,
     *   notes?: ?string,
     * }  $input
     */
    public function release(array $input): void
    {
        DB::transaction(function () use ($input): void {
            $needed = $this->aggregateLines($input['lines']);
            $notes = $input['notes'] ?? null;
            $userId = $input['userId'] ?? null;

            foreach ($needed as $variantId => $qty) {
                $reservedUuid = $this->movementUuid(
                    $input['referenceType'],
                    $input['referenceId'],
                    $variantId,
                    'release',
                    BalanceBucket::Reserved->value,
                );
                $availableUuid = $this->movementUuid(
                    $input['referenceType'],
                    $input['referenceId'],
                    $variantId,
                    'release',
                    BalanceBucket::Available->value,
                );

                $hasReserved = $this->movementExists($reservedUuid);
                $hasAvailable = $this->movementExists($availableUuid);

                if ($hasReserved && $hasAvailable) {
                    continue;
                }

                $lineNote = $notes ?? sprintf(
                    'Release for %s #%d',
                    $input['referenceType'],
                    $input['referenceId'],
                );
                $negQty = bcmul($qty, '-1', 4);

                if (! $hasReserved) {
                    $balance = $this->ledger->getLockedBalance(
                        $input['organizationId'],
                        LocationType::Store,
                        $input['locationId'],
                        $variantId,
                    );

                    if (bccomp((string) $balance->reserved, $qty, 4) < 0) {
                        throw new RuntimeException('Insufficient reserved stock to release.');
                    }

                    $this->ledger->post(new PostMovementData(
                        organizationId: $input['organizationId'],
                        locationType: LocationType::Store,
                        locationId: $input['locationId'],
                        productVariantId: $variantId,
                        type: MovementType::Release,
                        quantity: $negQty,
                        bucket: BalanceBucket::Reserved,
                        referenceType: $input['referenceType'],
                        referenceId: $input['referenceId'],
                        userId: $userId,
                        notes: $lineNote,
                        uuid: $reservedUuid,
                    ));
                }

                if (! $hasAvailable) {
                    $this->ledger->post(new PostMovementData(
                        organizationId: $input['organizationId'],
                        locationType: LocationType::Store,
                        locationId: $input['locationId'],
                        productVariantId: $variantId,
                        type: MovementType::Release,
                        quantity: $qty,
                        bucket: BalanceBucket::Available,
                        referenceType: $input['referenceType'],
                        referenceId: $input['referenceId'],
                        userId: $userId,
                        notes: $lineNote,
                        uuid: $availableUuid,
                    ));
                }
            }
        });
    }

    /**
     * Consume reserved stock (−reserved, MovementType::Sale). Does not touch available.
     * Idempotent when the consume movement UUID already exists.
     *
     * @param  array{
     *   organizationId: int,
     *   locationId: int,
     *   lines: list<array{productVariantId: int, quantity: string}>,
     *   referenceType: string,
     *   referenceId: int,
     *   userId?: ?int,
     *   notes?: ?string,
     * }  $input
     */
    public function consume(array $input): void
    {
        DB::transaction(function () use ($input): void {
            $needed = $this->aggregateLines($input['lines']);
            $notes = $input['notes'] ?? null;
            $userId = $input['userId'] ?? null;

            foreach ($needed as $variantId => $qty) {
                $uuid = $this->movementUuid(
                    $input['referenceType'],
                    $input['referenceId'],
                    $variantId,
                    'consume',
                    BalanceBucket::Reserved->value,
                );

                if ($this->movementExists($uuid)) {
                    continue;
                }

                $balance = $this->ledger->getLockedBalance(
                    $input['organizationId'],
                    LocationType::Store,
                    $input['locationId'],
                    $variantId,
                );

                if (bccomp((string) $balance->reserved, $qty, 4) < 0) {
                    throw new RuntimeException('Insufficient reserved stock to consume.');
                }

                $lineNote = $notes ?? sprintf(
                    'Consume for %s #%d',
                    $input['referenceType'],
                    $input['referenceId'],
                );
                $negQty = bcmul($qty, '-1', 4);

                try {
                    $this->ledger->post(new PostMovementData(
                        organizationId: $input['organizationId'],
                        locationType: LocationType::Store,
                        locationId: $input['locationId'],
                        productVariantId: $variantId,
                        type: MovementType::Sale,
                        quantity: $negQty,
                        bucket: BalanceBucket::Reserved,
                        referenceType: $input['referenceType'],
                        referenceId: $input['referenceId'],
                        userId: $userId,
                        notes: $lineNote,
                        uuid: $uuid,
                    ));
                } catch (QueryException $e) {
                    if ($this->isUniqueConstraintViolation($e) && $this->movementExists($uuid)) {
                        continue;
                    }

                    throw $e;
                }
            }
        });
    }

    /**
     * @param  list<array{productVariantId: int, quantity: string}>  $lines
     * @return array<int, string> variantId => qty
     */
    private function aggregateLines(array $lines): array
    {
        if ($lines === []) {
            throw new RuntimeException('Reservation requires at least one line.');
        }

        $needed = [];
        foreach ($lines as $line) {
            if (! array_key_exists('productVariantId', $line) || $line['productVariantId'] === null || $line['productVariantId'] === '') {
                throw new RuntimeException('Reservation product variant is required.');
            }

            $variantId = (int) $line['productVariantId'];
            if ($variantId <= 0) {
                throw new RuntimeException('Reservation product variant is required.');
            }

            $qty = (string) $line['quantity'];
            if (! preg_match('/^\d+(\.\d{1,4})?$/', $qty) || bccomp($qty, '0', 4) <= 0) {
                throw new RuntimeException('Reservation quantity must be a positive decimal.');
            }

            $needed[$variantId] = bcadd($needed[$variantId] ?? '0.0000', $qty, 4);
        }

        return $needed;
    }

    private function movementUuid(
        string $referenceType,
        int $referenceId,
        int $variantId,
        string $action,
        string $bucket,
    ): string {
        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            sprintf('%s:%d:%s:%d:%s', $referenceType, $referenceId, $action, $variantId, $bucket),
        )->toString();
    }

    private function movementExists(string $uuid): bool
    {
        return InventoryMovement::query()->where('uuid', $uuid)->exists();
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';
        $driverCode = (string) ($e->errorInfo[1] ?? '');
        $message = $e->getMessage();

        return $sqlState === '23000'
            || $driverCode === '19'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }
}
