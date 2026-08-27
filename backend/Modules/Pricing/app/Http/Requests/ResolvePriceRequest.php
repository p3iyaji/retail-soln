<?php

namespace Modules\Pricing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;

class ResolvePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->hasPermission('sales.create') === true
            || $user?->hasPermission('pricing.manage') === true
            || $user?->hasPermission('promotions.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'variantId' => [
                'required',
                'integer',
                Rule::exists('product_variants', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'storeId' => [
                'required',
                'integer',
                Rule::exists('stores', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'customerId' => ['nullable', 'integer'],
            'qty' => ['sometimes', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gt:0'],
        ];
    }
}
