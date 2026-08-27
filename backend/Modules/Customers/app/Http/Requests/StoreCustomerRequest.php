<?php

namespace Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Customers\Enums\CustomerType;
use Modules\Organization\Services\OrganizationService;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('customers.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => [
                'sometimes',
                'string',
                Rule::in([
                    CustomerType::Registered->value,
                    CustomerType::Business->value,
                    CustomerType::Corporate->value,
                ]),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'accountNumber' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'account_number')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'customerGroupId' => [
                'nullable',
                'integer',
                Rule::exists('customer_groups', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'creditLimit' => ['sometimes', 'regex:/^\d{1,11}(\.\d{1,4})?$/'],
            'balance' => ['sometimes', 'regex:/^\d{1,11}(\.\d{1,4})?$/'],
            'loyaltyRef' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'isActive' => ['sometimes', 'boolean'],
            'addresses' => ['sometimes', 'array'],
            'addresses.*.type' => ['nullable', 'string', Rule::in(['billing', 'shipping', 'other'])],
            'addresses.*.line1' => ['required', 'string', 'max:255'],
            'addresses.*.line2' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['nullable', 'string', 'max:100'],
            'addresses.*.region' => ['nullable', 'string', 'max:100'],
            'addresses.*.postalCode' => ['nullable', 'string', 'max:30'],
            'addresses.*.country' => ['nullable', 'string', 'max:100'],
            'addresses.*.isDefault' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customerAttributes(): array
    {
        $data = [
            'name' => $this->string('name')->toString(),
            'type' => $this->input('type', CustomerType::Registered->value),
            'phone' => $this->filled('phone') ? $this->string('phone')->toString() : null,
            'email' => $this->filled('email') ? $this->string('email')->toString() : null,
            'account_number' => $this->filled('accountNumber') ? $this->string('accountNumber')->toString() : null,
            'customer_group_id' => $this->filled('customerGroupId') ? (int) $this->input('customerGroupId') : null,
            'credit_limit' => $this->input('creditLimit', '0.0000'),
            'balance' => $this->input('balance', '0.0000'),
            'loyalty_ref' => $this->filled('loyaltyRef') ? $this->string('loyaltyRef')->toString() : null,
            'notes' => $this->filled('notes') ? $this->string('notes')->toString() : null,
            'is_active' => $this->boolean('isActive', true),
        ];

        if ($this->has('addresses')) {
            $data['addresses'] = $this->mapAddresses();
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapAddresses(): array
    {
        return collect($this->input('addresses', []))->map(fn (array $a) => [
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
}
