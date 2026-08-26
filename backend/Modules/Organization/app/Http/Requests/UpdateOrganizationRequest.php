<?php

namespace Modules\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Organization\Support\OrganizationAuthorization;

class UpdateOrganizationRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'legalName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'defaultCurrency' => ['sometimes', 'required', 'string', 'size:3'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
        ];
    }
}
