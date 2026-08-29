<?php

namespace Modules\Sync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;

class StoreDeviceRequest extends FormRequest
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

        return $user->canAccessStore($storeId);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('uuid')) {
            $this->merge([
                'uuid' => strtolower(trim((string) $this->input('uuid'))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'uuid' => [
                'required',
                'uuid',
                Rule::unique('devices', 'uuid')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'name' => ['required', 'string', 'max:255'],
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

    public function deviceUuid(): string
    {
        return strtolower((string) $this->validated('uuid'));
    }

    public function deviceName(): string
    {
        return (string) $this->validated('name');
    }

    public function enabled(): bool
    {
        if (! $this->exists('enabled')) {
            return true;
        }

        return (bool) $this->boolean('enabled');
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
