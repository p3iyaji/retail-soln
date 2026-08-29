<?php

namespace Modules\Sync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;

class SyncPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user?->hasPermission('sales.create') !== true) {
            return false;
        }

        $storeId = $this->resolveStoreId();
        if ($storeId === null) {
            return false;
        }

        return $user->canAccessStore($storeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('sync.push_batch_max', 50);

        return [
            'deviceUuid' => ['required', 'uuid'],
            'records' => ['required', 'array', 'min:1', 'max:'.$max],
            'records.*.uuid' => ['required', 'uuid'],
            'records.*.localSequence' => ['required', 'integer', 'min:1'],
            'records.*.payloadType' => ['required', 'string', 'max:32'],
            'records.*.createdAt' => ['nullable', 'string', 'max:64'],
            'records.*.payload' => ['required', 'array'],
            'records.*.payload.uuid' => ['sometimes', 'uuid'],
            'records.*.payload.sessionId' => ['nullable', 'integer'],
            'records.*.payload.customerId' => ['nullable', 'integer'],
            'records.*.payload.terminalId' => ['nullable', 'integer'],
            'records.*.payload.items' => ['required_if:records.*.payloadType,sale', 'array'],
            'records.*.payload.payments' => ['required_if:records.*.payloadType,sale', 'array'],
        ];
    }

    public function storeId(): int
    {
        $storeId = $this->resolveStoreId();
        if ($storeId === null) {
            abort(422, 'X-Store-Id header is required.');
        }

        $organizationId = app(OrganizationService::class)->current()->id;
        $exists = Store::query()
            ->where('organization_id', $organizationId)
            ->whereKey($storeId)
            ->exists();

        if (! $exists) {
            abort(422, 'Store not found.');
        }

        return $storeId;
    }

    /**
     * @return list<array{
     *   uuid: string,
     *   localSequence: int,
     *   payloadType: string,
     *   createdAt?: ?string,
     *   payload: array<string, mixed>,
     * }>
     */
    public function envelopes(): array
    {
        // Use input() for payload bodies — validated() omits nested keys without explicit rules.
        return collect($this->input('records', []))->map(fn (array $row) => [
            'uuid' => $row['uuid'],
            'localSequence' => (int) $row['localSequence'],
            'payloadType' => $row['payloadType'],
            'createdAt' => $row['createdAt'] ?? null,
            'payload' => is_array($row['payload'] ?? null) ? $row['payload'] : [],
        ])->all();
    }

    public function deviceUuid(): string
    {
        return (string) $this->input('deviceUuid');
    }

    private function resolveStoreId(): ?int
    {
        $header = $this->header('X-Store-Id');
        if ($header === null || $header === '' || ! ctype_digit((string) $header)) {
            return null;
        }

        return (int) $header;
    }
}
