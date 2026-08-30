<?php

namespace Modules\CustomerPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CustomerPortal\Models\Carrier;
use Modules\Organization\Services\OrganizationService;

class UpdateCarrierRequest extends FormRequest
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
        /** @var Carrier $carrier */
        $carrier = $this->route('carrier');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('carriers', 'code')
                    ->where(fn ($q) => $q->where('organization_id', $organizationId))
                    ->ignore($carrier->id),
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
        $attrs = [];

        if (array_key_exists('name', $v)) {
            $attrs['name'] = $v['name'];
        }
        if (array_key_exists('code', $v)) {
            $attrs['code'] = $v['code'];
        }
        if (array_key_exists('trackingUrlTemplate', $v)) {
            $attrs['tracking_url_template'] = $v['trackingUrlTemplate'];
        }
        if (array_key_exists('isActive', $v)) {
            $attrs['is_active'] = $v['isActive'];
        }

        return $attrs;
    }
}
