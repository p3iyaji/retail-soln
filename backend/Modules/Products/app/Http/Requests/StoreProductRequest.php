<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Support\CatalogCodeValidator;

class StoreProductRequest extends FormRequest
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100'],
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
            'basePrice' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
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
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            CatalogCodeValidator::validate($v, [
                'organizationId' => app(OrganizationService::class)->current()->id,
                'sku' => $this->input('sku'),
                'barcode' => $this->input('barcode'),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        $v = $this->validated();

        return [
            'name' => $v['name'],
            'sku' => $v['sku'],
            'barcode' => $v['barcode'] ?? null,
            'description' => $v['description'] ?? null,
            'category_id' => $v['categoryId'] ?? null,
            'brand_id' => $v['brandId'] ?? null,
            'unit_id' => $v['unitId'] ?? null,
            'supplier_id' => $v['supplierId'] ?? null,
            'base_price' => $v['basePrice'],
            'cost_price' => $v['costPrice'] ?? null,
            'reorder_level' => $v['reorderLevel'] ?? null,
            'min_stock' => $v['minStock'] ?? null,
            'max_stock' => $v['maxStock'] ?? null,
            'is_active' => $v['isActive'] ?? true,
        ];
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
