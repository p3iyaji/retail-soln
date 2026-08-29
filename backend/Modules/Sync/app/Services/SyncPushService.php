<?php

namespace Modules\Sync\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Enums\CashierSessionStatus;
use Modules\Sales\Models\CashierSession;
use Modules\Sales\Services\CashierSessionService;
use Modules\Sales\Services\IdempotencyConflictException;
use Modules\Sales\Services\SaleCompletionService;
use Modules\Sync\Enums\OversellExceptionStatus;
use Modules\Sync\Enums\SyncRecordStatus;
use Modules\Sync\Models\Device;
use Modules\Sync\Models\InventoryOversellException;
use Modules\Sync\Models\SyncBatch;
use Modules\Sync\Models\SyncRecord;
use RuntimeException;
use Throwable;

class SyncPushService
{
    public function __construct(
        private readonly SaleCompletionService $sales,
        private readonly CashierSessionService $sessions,
    ) {}

    /**
     * @param  list<array{
     *   uuid: string,
     *   localSequence: int,
     *   payloadType: string,
     *   createdAt?: ?string,
     *   payload: array<string, mixed>,
     * }>  $records
     * @return array{
     *   batchId: int,
     *   items: list<array{
     *     uuid: string,
     *     status: string,
     *     saleId?: int,
     *     exceptionId?: int,
     *     errors?: list<string>,
     *   }>
     * }
     */
    public function push(
        int $organizationId,
        int $storeId,
        int $userId,
        string $deviceUuid,
        array $records,
    ): array {
        $device = $this->resolveDevice($organizationId, $storeId, $deviceUuid);

        if (! $device->enabled) {
            throw new DeviceDisabledException('Device is disabled and cannot sync.');
        }

        $device->forceFill(['last_seen_at' => now(), 'store_id' => $storeId])->save();

        $ordered = collect($records)
            ->sortBy(fn (array $row) => (int) $row['localSequence'])
            ->values()
            ->all();

        $batch = SyncBatch::query()->create([
            'organization_id' => $organizationId,
            'store_id' => $storeId,
            'device_id' => $device->id,
            'user_id' => $userId,
            'record_count' => count($ordered),
        ]);

        $items = [];
        foreach ($ordered as $envelope) {
            $items[] = $this->processEnvelope(
                $batch,
                $organizationId,
                $storeId,
                $userId,
                $envelope,
            );
        }

        return [
            'batchId' => $batch->id,
            'items' => $items,
        ];
    }

    private function resolveDevice(int $organizationId, int $storeId, string $deviceUuid): Device
    {
        try {
            return Device::query()->firstOrCreate(
                [
                    'organization_id' => $organizationId,
                    'uuid' => $deviceUuid,
                ],
                [
                    'store_id' => $storeId,
                    'enabled' => true,
                    'last_seen_at' => now(),
                ],
            );
        } catch (QueryException $e) {
            if (! $this->isUniqueDeviceViolation($e)) {
                throw $e;
            }

            $device = Device::query()
                ->where('organization_id', $organizationId)
                ->where('uuid', $deviceUuid)
                ->first();

            if ($device === null) {
                throw $e;
            }

            return $device;
        }
    }

    private function isUniqueDeviceViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'devices_organization_id_uuid')
            || (str_contains($message, 'unique') && str_contains($message, 'uuid'));
    }

    /**
     * @param  array{
     *   uuid: string,
     *   localSequence: int,
     *   payloadType: string,
     *   createdAt?: ?string,
     *   payload: array<string, mixed>,
     * }  $envelope
     * @return array{
     *   uuid: string,
     *   status: string,
     *   saleId?: int,
     *   exceptionId?: int,
     *   errors?: list<string>,
     * }
     */
    private function processEnvelope(
        SyncBatch $batch,
        int $organizationId,
        int $storeId,
        int $userId,
        array $envelope,
    ): array {
        $uuid = $envelope['uuid'];

        $prior = SyncRecord::query()
            ->where('organization_id', $organizationId)
            ->where('uuid', $uuid)
            ->first();

        // Only synced records are idempotent; error rows stay retryable.
        if ($prior !== null && $prior->status === SyncRecordStatus::Synced) {
            return $this->itemFromRecord($prior);
        }

        if ($envelope['payloadType'] !== 'sale') {
            return $this->persistError(
                $batch,
                $organizationId,
                $uuid,
                (int) $envelope['localSequence'],
                $envelope['payloadType'],
                ['payloadType "'.$envelope['payloadType'].'" is not supported in this sync version.'],
                $prior,
            );
        }

        $payload = $envelope['payload'];
        if (array_key_exists('uuid', $payload) && (string) $payload['uuid'] !== $uuid) {
            return $this->persistError(
                $batch,
                $organizationId,
                $uuid,
                (int) $envelope['localSequence'],
                'sale',
                ['envelope.uuid must equal payload.uuid.'],
                $prior,
            );
        }

        if (isset($payload['installmentPlan']) && $payload['installmentPlan'] !== null) {
            return $this->persistError(
                $batch,
                $organizationId,
                $uuid,
                (int) $envelope['localSequence'],
                'sale',
                ['Installment plan creation is online-only and cannot be synced from offline queue.'],
                $prior,
            );
        }

        try {
            return DB::transaction(function () use ($batch, $organizationId, $storeId, $userId, $envelope, $uuid, $prior, $payload) {
                $locked = SyncRecord::query()
                    ->where('organization_id', $organizationId)
                    ->where('uuid', $uuid)
                    ->lockForUpdate()
                    ->first();

                if ($locked !== null && $locked->status === SyncRecordStatus::Synced) {
                    return $this->itemFromRecord($locked);
                }

                $sessionId = $this->resolveSessionId(
                    $organizationId,
                    $storeId,
                    $userId,
                    isset($payload['sessionId']) ? (int) $payload['sessionId'] : null,
                );

                if ($sessionId === null) {
                    return $this->persistError(
                        $batch,
                        $organizationId,
                        $uuid,
                        (int) $envelope['localSequence'],
                        'sale',
                        ['No open cashier session for this user and store.'],
                        $locked ?? $prior,
                    );
                }

                $result = $this->sales->complete([
                    'organizationId' => $organizationId,
                    'storeId' => $storeId,
                    'userId' => $userId,
                    'uuid' => $uuid,
                    'customerId' => isset($payload['customerId']) ? (int) $payload['customerId'] : null,
                    'sessionId' => $sessionId,
                    'terminalId' => isset($payload['terminalId']) ? (int) $payload['terminalId'] : null,
                    'idempotencyKey' => $uuid,
                    'items' => $this->normalizeItems($payload['items'] ?? []),
                    'payments' => $this->normalizePayments($payload['payments'] ?? []),
                    'allowOversell' => true,
                ]);

                $exceptionId = null;
                if ($result['created'] && $result['oversells'] !== []) {
                    foreach ($result['oversells'] as $oversell) {
                        $row = InventoryOversellException::query()->create([
                            'organization_id' => $organizationId,
                            'store_id' => $storeId,
                            'sale_id' => $result['sale']->id,
                            'product_variant_id' => $oversell['variantId'],
                            'qty_deficit' => $oversell['qtyDeficit'],
                            'status' => OversellExceptionStatus::Pending,
                        ]);
                        $exceptionId ??= $row->id;
                    }
                } elseif (! $result['created']) {
                    $existingException = InventoryOversellException::query()
                        ->where('organization_id', $organizationId)
                        ->where('sale_id', $result['sale']->id)
                        ->orderBy('id')
                        ->first();
                    $exceptionId = $existingException?->id;
                }

                $attrs = [
                    'organization_id' => $organizationId,
                    'sync_batch_id' => $batch->id,
                    'uuid' => $uuid,
                    'local_sequence' => (int) $envelope['localSequence'],
                    'payload_type' => 'sale',
                    'status' => SyncRecordStatus::Synced,
                    'sale_id' => $result['sale']->id,
                    'exception_id' => $exceptionId,
                    'errors' => null,
                    'server_result' => [
                        'saleId' => $result['sale']->id,
                        'exceptionId' => $exceptionId,
                        'created' => $result['created'],
                    ],
                ];

                $record = $locked ?? $prior;
                if ($record !== null) {
                    $record->fill($attrs)->save();
                } else {
                    $record = SyncRecord::query()->create($attrs);
                }

                return $this->itemFromRecord($record->fresh());
            });
        } catch (IdempotencyConflictException $e) {
            return $this->persistError(
                $batch,
                $organizationId,
                $uuid,
                (int) $envelope['localSequence'],
                'sale',
                [$e->getMessage()],
                $prior,
            );
        } catch (RuntimeException $e) {
            return $this->persistError(
                $batch,
                $organizationId,
                $uuid,
                (int) $envelope['localSequence'],
                'sale',
                [$e->getMessage()],
                $prior,
            );
        } catch (Throwable $e) {
            return $this->persistError(
                $batch,
                $organizationId,
                $uuid,
                (int) $envelope['localSequence'],
                'sale',
                ['Sync failed: '.$e->getMessage()],
                $prior,
            );
        }
    }

    private function resolveSessionId(
        int $organizationId,
        int $storeId,
        int $userId,
        ?int $sessionId,
    ): ?int {
        if ($sessionId !== null) {
            $original = CashierSession::query()
                ->where('organization_id', $organizationId)
                ->whereKey($sessionId)
                ->first();

            if (
                $original !== null
                && $original->status === CashierSessionStatus::Open
                && $original->store_id === $storeId
                && $original->user_id === $userId
            ) {
                return $original->id;
            }
        }

        return $this->sessions->findOpenForUserStore($organizationId, $storeId, $userId)?->id;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array{variantId: int, qty: string, unitPrice: string, discount: string}>
     */
    private function normalizeItems(array $items): array
    {
        return collect($items)->map(fn (array $item) => [
            'variantId' => (int) $item['variantId'],
            'qty' => bcadd((string) $item['qty'], '0', 4),
            'unitPrice' => bcadd((string) $item['unitPrice'], '0', 4),
            'discount' => bcadd((string) ($item['discount'] ?? '0'), '0', 4),
        ])->all();
    }

    /**
     * @param  list<mixed>  $payments
     * @return list<array{method: string, amount: string, provider: string, reference?: ?string}>
     */
    private function normalizePayments(array $payments): array
    {
        return collect($payments)->map(fn (array $payment) => [
            'method' => $payment['method'],
            'amount' => bcadd((string) $payment['amount'], '0', 4),
            'provider' => $payment['provider'] ?? 'manual',
            'reference' => $payment['reference'] ?? null,
        ])->all();
    }

    /**
     * @param  list<string>  $errors
     * @return array{uuid: string, status: string, errors: list<string>}
     */
    private function persistError(
        SyncBatch $batch,
        int $organizationId,
        string $uuid,
        int $localSequence,
        string $payloadType,
        array $errors,
        ?SyncRecord $existing = null,
    ): array {
        $attrs = [
            'organization_id' => $organizationId,
            'sync_batch_id' => $batch->id,
            'uuid' => $uuid,
            'local_sequence' => $localSequence,
            'payload_type' => $payloadType,
            'status' => SyncRecordStatus::Error,
            'sale_id' => null,
            'exception_id' => null,
            'errors' => $errors,
            'server_result' => ['errors' => $errors],
        ];

        if ($existing !== null) {
            if ($existing->status === SyncRecordStatus::Synced) {
                return $this->itemFromRecord($existing);
            }
            $existing->fill($attrs)->save();
            $record = $existing->fresh();
        } else {
            $record = SyncRecord::query()->create($attrs);
        }

        return $this->itemFromRecord($record);
    }

    /**
     * @return array{
     *   uuid: string,
     *   status: string,
     *   saleId?: int,
     *   exceptionId?: int,
     *   errors?: list<string>,
     * }
     */
    private function itemFromRecord(SyncRecord $record): array
    {
        $item = [
            'uuid' => $record->uuid,
            'status' => $record->status instanceof SyncRecordStatus
                ? $record->status->value
                : (string) $record->status,
        ];

        if ($record->sale_id !== null) {
            $item['saleId'] = $record->sale_id;
        }

        if ($record->exception_id !== null) {
            $item['exceptionId'] = $record->exception_id;
        }

        if (is_array($record->errors) && $record->errors !== []) {
            $item['errors'] = array_values($record->errors);
        }

        return $item;
    }
}
