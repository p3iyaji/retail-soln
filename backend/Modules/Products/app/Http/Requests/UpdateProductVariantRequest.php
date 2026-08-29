<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Support\CatalogCodeValidator;

class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('products.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => ['sometimes', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'price' => ['sometimes', 'regex:/^\d+(\.\d{1,4})?$/'],
            'costPrice' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'isDefault' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        /** @var ProductVariant $variant */
        $variant = $this->route('variant');

        $validator->after(function (Validator $v) use ($variant): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            CatalogCodeValidator::validate($v, [
                'organizationId' => app(OrganizationService::class)->current()->id,
                'sku' => $this->has('sku') ? $this->input('sku') : null,
                'barcode' => $this->has('barcode') ? $this->input('barcode') : null,
                'ignoreProductId' => $variant->product_id,
                'ignoreVariantId' => $variant->id,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function variantAttributes(): array
    {
        $v = $this->validated();
        $map = [
            'name' => 'name',
            'sku' => 'sku',
            'price' => 'price',
            'costPrice' => 'cost_price',
            'isDefault' => 'is_default',
            'isActive' => 'is_active',
            'sortOrder' => 'sort_order',
        ];

        $attrs = [];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $v)) {
                $attrs[$column] = $v[$input];
            }
        }

        return $attrs;
    }

    public function hasBarcode(): bool
    {
        return array_key_exists('barcode', $this->validated());
    }

    public function barcodeCode(): ?string
    {
        if (! $this->hasBarcode()) {
            return null;
        }

        $code = $this->validated()['barcode'];

        return ($code === null || $code === '') ? null : $code;
    }
}
