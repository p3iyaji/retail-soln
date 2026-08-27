<?php

namespace Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;

class UpdateCustomerGroupRequest extends FormRequest
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
        $groupId = (int) $this->route('customerGroup')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customer_groups', 'code')
                    ->where(fn ($q) => $q->where('organization_id', $organizationId))
                    ->ignore($groupId),
            ],
        ];
    }

    /**
     * @return array{name?: string, code?: ?string}
     */
    public function groupAttributes(): array
    {
        $data = [];
        if ($this->exists('name')) {
            $data['name'] = $this->string('name')->toString();
        }
        if ($this->exists('code')) {
            $data['code'] = $this->filled('code') ? $this->string('code')->toString() : null;
        }

        return $data;
    }
}
