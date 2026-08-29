<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Support\CatalogCodeValidator;

class UpdateProductRequest extends FormRequest
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
        $organizationId = app(OrganizationService::class)->current()->id;
        /** @var Product $product */
        $product = $this->route('product');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => ['sometimes', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'categoryId' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'brandId' => [
                'nullable',
                'integer',
                Rule::exists('brands', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'unitId' => [
                'nullable',
                'integer',
                Rule::exists('units', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'supplierId' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'sellingPrice' => ['sometimes', 'regex:/^\d+(\.\d{1,4})?$/'],
            'costPrice' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'reorderLevel' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'minStock' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'maxStock' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'isActive' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array'],
            'images.*.url' => ['required_with:images', 'string', 'max:2048'],
            'images.*.altText' => ['nullable', 'string', 'max:255'],
            'images.*.sortOrder' => ['nullable', 'integer', 'min:0'],
            'images.*.isPrimary' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        /** @var Product $product */
        $product = $this->route('product');

        $validator->after(function (Validator $v) use ($product): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            CatalogCodeValidator::validate($v, [
                'organizationId' => app(OrganizationService::class)->current()->id,
                'sku' => $this->has('sku') ? $this->input('sku') : null,
                'barcode' => $this->has('barcode') ? $this->input('barcode') : null,
                'ignoreProductId' => $product->id,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        $v = $this->validated();
        $map = [
            'name' => 'name',
            'sku' => 'sku',
            'barcode' => 'barcode',
            'description' => 'description',
            'categoryId' => 'category_id',
            'brandId' => 'brand_id',
            'unitId' => 'unit_id',
            'supplierId' => 'supplier_id',
            'sellingPrice' => 'selling_price',
            'costPrice' => 'cost_price',
            'reorderLevel' => 'reorder_level',
            'minStock' => 'min_stock',
            'maxStock' => 'max_stock',
            'isActive' => 'is_active',
        ];

        $attrs = [];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $v)) {
                $attrs[$column] = $v[$input];
            }
        }

        return $attrs;
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
