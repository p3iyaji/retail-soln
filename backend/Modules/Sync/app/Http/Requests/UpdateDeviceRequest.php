<?php

namespace Modules\Sync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Sync\Models\Device;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user?->hasPermission('devices.manage') !== true) {
            return false;
        }

        $storeId = $this->resolveStoreId();
        if ($storeId === null) {
            abort(422, 'X-Store-Id header is required.');
        }

        if (! $user->canAccessStore($storeId)) {
            return false;
        }

        $device = $this->route('device');
        if (! $device instanceof Device) {
            return false;
        }

        return (int) $device->store_id === $storeId;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
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
     * @return array{name?: string, enabled?: bool}
     */
    public function attributesForUpdate(): array
    {
        $out = [];
        if ($this->exists('name')) {
            $out['name'] = (string) $this->validated('name');
        }
        if ($this->exists('enabled')) {
            $out['enabled'] = $this->boolean('enabled');
        }

        return $out;
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
