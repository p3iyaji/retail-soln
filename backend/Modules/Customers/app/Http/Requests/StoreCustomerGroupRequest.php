<?php

namespace Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;

class StoreCustomerGroupRequest extends FormRequest
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
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customer_groups', 'code')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
        ];
    }

    /**
     * @return array{name: string, code: ?string}
     */
    public function groupAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'code' => $this->filled('code') ? $this->string('code')->toString() : null,
        ];
    }
}
