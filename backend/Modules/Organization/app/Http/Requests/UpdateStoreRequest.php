<?php

namespace Modules\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Models\Store;

class UpdateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Store|null $store */
        $store = $this->route('store');

        return $store instanceof Store
            && $this->user()?->can('update', $store) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Store $store */
        $store = $this->route('store');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('stores', 'code')
                    ->where(fn ($q) => $q->where('organization_id', $store->organization_id))
                    ->ignore($store->id),
            ],
            'addressLine1' => ['nullable', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postalCode' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'size:2'],
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'taxConfig' => ['nullable', 'array'],
            'taxConfig.rate' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'taxConfig.inclusive' => ['nullable', 'boolean'],
            'taxConfig.name' => ['nullable', 'string', 'max:100'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function storeAttributes(): array
    {
        $v = $this->validated();
        $map = [
            'name' => 'name',
            'code' => 'code',
            'addressLine1' => 'address_line1',
            'addressLine2' => 'address_line2',
            'city' => 'city',
            'state' => 'state',
            'postalCode' => 'postal_code',
            'country' => 'country',
            'currency' => 'currency',
            'taxConfig' => 'tax_config',
            'isActive' => 'is_active',
        ];

        $attributes = [];
        foreach ($map as $input => $column) {
            if (! array_key_exists($input, $v)) {
                continue;
            }
            $value = $v[$input];
            if (in_array($column, ['country', 'currency'], true) && is_string($value)) {
                $value = strtoupper($value);
            }
            $attributes[$column] = $value;
        }

        return $attributes;
    }
}
