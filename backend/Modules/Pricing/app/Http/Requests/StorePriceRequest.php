<?php

namespace Modules\Pricing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;

class StorePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pricing.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'priceListId' => [
                'required',
                'integer',
                Rule::exists('price_lists', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'productVariantId' => [
                'required',
                'integer',
                Rule::exists('product_variants', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }

    /**
     * @return array{price_list_id: int, product_variant_id: int, amount: string}
     */
    public function attributesPayload(): array
    {
        return [
            'price_list_id' => $this->integer('priceListId'),
            'product_variant_id' => $this->integer('productVariantId'),
            'amount' => $this->string('amount')->toString(),
        ];
    }
}
