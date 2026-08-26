<?php

namespace Modules\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Organization\Support\OrganizationAuthorization;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return OrganizationAuthorization::canManage($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'storeId' => [
                'nullable',
                'integer',
                Rule::exists('stores', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'addressLine1' => ['nullable', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postalCode' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'size:2'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function warehouseAttributes(): array
    {
        $v = $this->validated();

        return [
            'name' => $v['name'],
            'code' => $v['code'] ?? null,
            'store_id' => $v['storeId'] ?? null,
            'address_line1' => $v['addressLine1'] ?? null,
            'address_line2' => $v['addressLine2'] ?? null,
            'city' => $v['city'] ?? null,
            'state' => $v['state'] ?? null,
            'postal_code' => $v['postalCode'] ?? null,
            'country' => isset($v['country']) ? strtoupper($v['country']) : null,
            'is_active' => $v['isActive'] ?? true,
        ];
    }
}
