<?php

namespace Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Customers\Enums\CustomerType;
use Modules\Organization\Services\OrganizationService;

class UpdateCustomerRequest extends FormRequest
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
        $customerId = (int) $this->route('customer')?->id;
        $money = ['sometimes', 'regex:/^\d{1,11}(\.\d{1,4})?$/'];

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => [
                'sometimes',
                'string',
                Rule::in([
                    CustomerType::WalkIn->value,
                    CustomerType::Registered->value,
                    CustomerType::Business->value,
                    CustomerType::Corporate->value,
                ]),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'accountNumber' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('customers', 'account_number')
                    ->where(fn ($q) => $q->where('organization_id', $organizationId))
                    ->ignore($customerId),
            ],
            'customerGroupId' => [
                'nullable',
                'integer',
                Rule::exists('customer_groups', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'creditLimit' => $money,
            'balance' => $money,
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
        $data = [];

        foreach ([
            'name' => 'name',
            'type' => 'type',
            'phone' => 'phone',
            'email' => 'email',
            'loyaltyRef' => 'loyalty_ref',
            'notes' => 'notes',
        ] as $input => $attr) {
            if ($this->exists($input)) {
                $value = $this->input($input);
                $data[$attr] = $value === null || $value === '' ? null : $value;
            }
        }

        if ($this->exists('accountNumber')) {
            $data['account_number'] = $this->string('accountNumber')->toString();
        }
        if ($this->exists('customerGroupId')) {
            $data['customer_group_id'] = $this->filled('customerGroupId')
                ? (int) $this->input('customerGroupId')
                : null;
        }
        if ($this->exists('creditLimit')) {
            $data['credit_limit'] = $this->input('creditLimit');
        }
        if ($this->exists('balance')) {
            $data['balance'] = $this->input('balance');
        }
        if ($this->exists('isActive')) {
            $data['is_active'] = $this->boolean('isActive');
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

        return $data;
    }
}
