<?php

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('suppliers.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(\Modules\Organization\Services\OrganizationService::class)->current()->id;
        $supplierId = (int) $this->route('supplier')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('suppliers', 'code')
                    ->where(fn ($q) => $q->where('organization_id', $organizationId))
                    ->ignore($supplierId),
            ],
            'isActive' => ['sometimes', 'boolean'],
            'contacts' => ['sometimes', 'array'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.title' => ['nullable', 'string', 'max:100'],
            'contacts.*.isPrimary' => ['sometimes', 'boolean'],
            'addresses' => ['sometimes', 'array'],
            'addresses.*.type' => ['nullable', 'string', Rule::in(['billing', 'shipping', 'other'])],
            'addresses.*.line1' => ['required', 'string', 'max:255'],
            'addresses.*.line2' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['nullable', 'string', 'max:100'],
            'addresses.*.region' => ['nullable', 'string', 'max:100'],
            'addresses.*.postalCode' => ['nullable', 'string', 'max:30'],
            'addresses.*.country' => ['nullable', 'string', 'max:100'],
            'addresses.*.isDefault' => ['sometimes', 'boolean'],
            'productMappings' => ['sometimes', 'array'],
            'productMappings.*.productId' => [
                'nullable',
                'required_without:productMappings.*.productSku',
                'integer',
                'distinct',
                Rule::exists('products', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'productMappings.*.productSku' => [
                'nullable',
                'required_without:productMappings.*.productId',
                'string',
                'max:100',
            ],
            'productMappings.*.supplierSku' => ['nullable', 'string', 'max:100'],
            'productMappings.*.cost' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mappings = $this->input('productMappings', []);
            if (! is_array($mappings)) {
                return;
            }

            $seen = [];
            foreach ($mappings as $index => $mapping) {
                if (! is_array($mapping)) {
                    continue;
                }
                $key = isset($mapping['productId']) && $mapping['productId'] !== null && $mapping['productId'] !== ''
                    ? 'id:'.$mapping['productId']
                    : (isset($mapping['productSku']) && $mapping['productSku'] !== ''
                        ? 'sku:'.strtolower((string) $mapping['productSku'])
                        : null);
                if ($key === null) {
                    continue;
                }
                if (isset($seen[$key])) {
                    $validator->errors()->add(
                        "productMappings.{$index}.productId",
                        'Duplicate product mapping.'
                    );
                }
                $seen[$key] = true;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierAttributes(): array
    {
        $data = [];

        if ($this->has('name')) {
            $data['name'] = $this->string('name')->toString();
        }
        if ($this->exists('code')) {
            $data['code'] = $this->filled('code') ? $this->string('code')->toString() : null;
        }
        if ($this->has('isActive')) {
            $data['is_active'] = $this->boolean('isActive');
        }

        if ($this->has('contacts')) {
            $data['contacts'] = collect($this->input('contacts', []))->map(fn (array $c) => [
                'name' => $c['name'],
                'email' => $c['email'] ?? null,
                'phone' => $c['phone'] ?? null,
                'title' => $c['title'] ?? null,
                'is_primary' => (bool) ($c['isPrimary'] ?? false),
            ])->all();
        }

        if ($this->has('addresses')) {
            $data['addresses'] = collect($this->input('addresses', []))->map(fn (array $a) => [
                'type' => $a['type'] ?? 'other',
                'line1' => $a['line1'],
                'line2' => $a['line2'] ?? null,
                'city' => $a['city'] ?? null,
                'region' => $a['region'] ?? null,
                'postal_code' => $a['postalCode'] ?? null,
                'country' => $a['country'] ?? null,
                'is_default' => (bool) ($a['isDefault'] ?? false),
            ])->all();
        }

        if ($this->has('productMappings')) {
            $data['product_mappings'] = collect($this->input('productMappings', []))->map(fn (array $m) => [
                'product_id' => $m['productId'] ?? null,
                'product_sku' => $m['productSku'] ?? null,
                'supplier_sku' => $m['supplierSku'] ?? null,
                'cost' => $m['cost'] ?? null,
            ])->all();
        }

        return $data;
    }
}
