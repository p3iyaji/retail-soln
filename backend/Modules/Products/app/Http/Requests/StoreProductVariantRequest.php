<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Support\CatalogCodeValidator;

class StoreProductVariantRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'costPrice' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'isDefault' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
            'images' => ['sometimes', 'array'],
            'images.*.url' => ['required_with:images', 'string', 'max:2048'],
            'images.*.altText' => ['nullable', 'string', 'max:255'],
            'images.*.sortOrder' => ['nullable', 'integer', 'min:0'],
            'images.*.isPrimary' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            /** @var Product $product */
            $product = $this->route('product');

            CatalogCodeValidator::validate($v, [
                'organizationId' => app(OrganizationService::class)->current()->id,
                'sku' => $this->input('sku'),
                'barcode' => $this->input('barcode'),
                'ignoreProductId' => $product->id,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function variantAttributes(Product $product): array
    {
        $v = $this->validated();

        return [
            'organization_id' => $product->organization_id,
            'product_id' => $product->id,
            'name' => $v['name'],
            'sku' => $v['sku'],
            'price' => $v['price'],
            'cost_price' => $v['costPrice'] ?? null,
            'is_default' => $v['isDefault'] ?? false,
            'is_active' => $v['isActive'] ?? true,
            'sort_order' => $v['sortOrder'] ?? 0,
        ];
    }

    public function barcodeCode(): ?string
    {
        $code = $this->validated()['barcode'] ?? null;

        return ($code === null || $code === '') ? null : $code;
    }

    public function hasImages(): bool
    {
        return array_key_exists('images', $this->validated());
    }

    /**
     * @return list<array{url: string, alt_text: ?string, sort_order: int, is_primary: bool}>
     */
    public function imageAttributes(): array
    {
        $images = $this->validated()['images'] ?? [];

        return collect($images)->values()->map(fn (array $image, int $index) => [
            'url' => $image['url'],
            'alt_text' => $image['altText'] ?? null,
            'sort_order' => $image['sortOrder'] ?? $index,
            'is_primary' => (bool) ($image['isPrimary'] ?? $index === 0),
        ])->all();
    }
}
