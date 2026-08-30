<?php

namespace Modules\CustomerPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;

class StoreCarrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('carriers.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('carriers', 'code')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'trackingUrlTemplate' => ['nullable', 'string', 'max:500'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function carrierAttributes(): array
    {
        $v = $this->validated();

        return [
            'name' => $v['name'],
            'code' => $v['code'] ?? null,
            'tracking_url_template' => $v['trackingUrlTemplate'] ?? null,
            'is_active' => $v['isActive'] ?? true,
        ];
    }
}
