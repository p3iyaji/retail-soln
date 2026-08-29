<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\BalanceBucket;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryMovement;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Dual-post available↔reserved holds via InventoryLedgerService (AD-2).
 *
 * Portal place-order reserves; cancel / unpaid TTL release. Payment success
 * must not call this service — consume reserved→sale is Story 7.2+.
 */
class StockReservationService
{
    public function __construct(private readonly InventoryLedgerService $ledger) {}

    /**
     * Move available → reserved at a store location. Caller must own the DB transaction.
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

            if ($this->movementExists($availableUuid)) {
                continue;
            }

            $balance = $this->ledger->getLockedBalance(
                $input['organizationId'],
                LocationType::Store,
                $input['locationId'],
                $variantId,
            );

            if (bccomp((string) $balance->available, $qty, 4) < 0) {
                throw new RuntimeException('Insufficient available stock for one or more items.');
            }

            $negQty = bcmul($qty, '-1', 4);
            $lineNote = $notes ?? sprintf(
                'Reserve for %s #%d',
                $input['referenceType'],
                $input['referenceId'],
            );

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
                uuid: $this->movementUuid(
                    $input['referenceType'],
                    $input['referenceId'],
                    $variantId,
                    'reserve',
                    BalanceBucket::Reserved->value,
                ),
            ));
        }
    }

    /**
     * Move reserved → available. Idempotent when release movements already exist.
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

            if ($this->movementExists($reservedUuid)) {
                continue;
            }

            $balance = $this->ledger->getLockedBalance(
                $input['organizationId'],
                LocationType::Store,
                $input['locationId'],
                $variantId,
            );

            if (bccomp((string) $balance->reserved, $qty, 4) < 0) {
                throw new RuntimeException('Insufficient reserved stock to release.');
            }

            $negQty = bcmul($qty, '-1', 4);
            $lineNote = $notes ?? sprintf(
                'Release for %s #%d',
                $input['referenceType'],
                $input['referenceId'],
            );

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
                uuid: $this->movementUuid(
                    $input['referenceType'],
                    $input['referenceId'],
                    $variantId,
                    'release',
                    BalanceBucket::Available->value,
                ),
            ));
        }
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
            $qty = (string) $line['quantity'];
            if (! preg_match('/^\d+(\.\d{1,4})?$/', $qty) || bccomp($qty, '0', 4) <= 0) {
                throw new RuntimeException('Reservation quantity must be a positive decimal.');
            }

            $variantId = (int) $line['productVariantId'];
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
}
