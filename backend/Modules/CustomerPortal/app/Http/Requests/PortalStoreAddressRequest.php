<?php

namespace Modules\CustomerPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PortalStoreAddressRequest extends FormRequest
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
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postalCode' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:120'],
            'isDefault' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function portalPayload(): array
    {
        $v = $this->validated();

        return [
            'type' => $v['type'] ?? 'shipping',
            'line1' => $v['line1'],
            'line2' => $v['line2'] ?? null,
            'city' => $v['city'] ?? null,
            'region' => $v['region'] ?? null,
            'postal_code' => $v['postalCode'] ?? null,
            'country' => $v['country'] ?? null,
            'is_default' => $v['isDefault'] ?? false,
        ];
    }
}
