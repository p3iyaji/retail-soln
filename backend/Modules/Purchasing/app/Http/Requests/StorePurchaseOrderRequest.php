<?php

namespace Modules\Purchasing\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchase.create') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'storeId' => [
                'required',
                'integer',
                Rule::exists('stores', 'id')->where('organization_id', $organizationId),
            ],
            'supplierId' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)->where('is_active', true)
                ),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.productVariantId' => [
                'required',
                'integer',
                Rule::exists('product_variants', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)->where('is_active', true)
                ),
            ],
            'items.*.qty' => [
                'required',
                'regex:/^\d+(\.\d{1,4})?$/',
                $this->positiveDecimalRule(),
            ],
            'items.*.unitCost' => [
                'required',
                'regex:/^\d+(\.\d{1,4})?$/',
                $this->nonNegativeDecimalRule(),
            ],
        ];
    }

    private function positiveDecimalRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_scalar($value) || bccomp((string) $value, '0', 4) <= 0) {
                $fail("The {$attribute} must be greater than zero.");
            }
        };
    }

    private function nonNegativeDecimalRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_scalar($value) || bccomp((string) $value, '0', 4) < 0) {
                $fail("The {$attribute} must be greater than or equal to zero.");
            }
        };
    }

    /**
     * @return array{
     *   storeId: int,
     *   supplierId: int,
     *   notes: ?string,
     *   items: list<array{productVariantId: int, qty: string, unitCost: string}>
     * }
     */
    public function purchaseOrderAttributes(): array
    {
        return [
            'storeId' => (int) $this->integer('storeId'),
            'supplierId' => (int) $this->integer('supplierId'),
            'notes' => $this->filled('notes') ? $this->string('notes')->toString() : null,
            'items' => collect($this->input('items', []))->map(fn (array $item) => [
                'productVariantId' => (int) $item['productVariantId'],
                'qty' => (string) $item['qty'],
                'unitCost' => (string) $item['unitCost'],
            ])->all(),
        ];
    }
}
