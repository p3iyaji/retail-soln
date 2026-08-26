<?php

namespace Modules\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Models\Warehouse;
use Modules\Organization\Support\OrganizationAuthorization;

class UpdateWarehouseRequest extends FormRequest
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
        /** @var Warehouse $warehouse */
        $warehouse = $this->route('warehouse');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')
                    ->where(fn ($q) => $q->where('organization_id', $warehouse->organization_id))
                    ->ignore($warehouse->id),
            ],
            'storeId' => [
                'nullable',
                'integer',
                Rule::exists('stores', 'id')->where(
                    fn ($q) => $q->where('organization_id', $warehouse->organization_id)
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
        $map = [
            'name' => 'name',
            'code' => 'code',
            'storeId' => 'store_id',
            'addressLine1' => 'address_line1',
            'addressLine2' => 'address_line2',
            'city' => 'city',
            'state' => 'state',
            'postalCode' => 'postal_code',
            'country' => 'country',
            'isActive' => 'is_active',
        ];

        $attributes = [];
        foreach ($map as $input => $column) {
            if (! array_key_exists($input, $v)) {
                continue;
            }
            $value = $v[$input];
            if ($column === 'country' && is_string($value)) {
                $value = strtoupper($value);
            }
            $attributes[$column] = $value;
        }

        return $attributes;
    }
}
