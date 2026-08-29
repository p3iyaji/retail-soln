<?php

namespace Modules\CustomerPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PortalUpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::in(['billing', 'shipping', 'other'])],
            'line1' => ['sometimes', 'required', 'string', 'max:255'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postalCode' => ['sometimes', 'nullable', 'string', 'max:40'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'isDefault' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function portalPayload(): array
    {
        $v = $this->validated();
        $out = [];

        foreach ([
            'type' => 'type',
            'line1' => 'line1',
            'line2' => 'line2',
            'city' => 'city',
            'region' => 'region',
            'postalCode' => 'postal_code',
            'country' => 'country',
            'isDefault' => 'is_default',
        ] as $input => $column) {
            if (array_key_exists($input, $v)) {
                $out[$column] = $v[$input];
            }
        }

        return $out;
    }
}
