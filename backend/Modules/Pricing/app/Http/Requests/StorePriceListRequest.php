<?php

namespace Modules\Pricing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;

class StorePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pricing.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'storeId' => [
                'nullable',
                'integer',
                Rule::exists('stores', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'isActive' => ['sometimes', 'boolean'],
            'isDefault' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{name: string, store_id: int|null, is_active: bool, is_default: bool}
     */
    public function attributesPayload(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'store_id' => $this->filled('storeId') ? $this->integer('storeId') : null,
            'is_active' => $this->boolean('isActive', true),
            'is_default' => $this->boolean('isDefault', false),
        ];
    }
}
