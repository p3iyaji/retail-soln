<?php

namespace Modules\Purchasing\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchase.receive') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;
        $purchaseOrderId = (int) $this->input('purchaseOrderId');

        return [
            'purchaseOrderId' => [
                'required',
                'integer',
                Rule::exists('purchase_orders', 'id')->where('organization_id', $organizationId),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.purchaseOrderItemId' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('purchase_order_items', 'id')->where(function ($query) use ($organizationId, $purchaseOrderId) {
                    $query->where('organization_id', $organizationId)
                        ->where('purchase_order_id', $purchaseOrderId);
                }),
            ],
            'items.*.qty' => [
                'required',
                'regex:/^\d+(\.\d{1,4})?$/',
                $this->positiveDecimalRule(),
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

    /**
     * @return array{
     *   purchaseOrderId: int,
     *   notes: ?string,
     *   items: list<array{purchaseOrderItemId: int, qty: string}>
     * }
     */
    public function goodsReceiptAttributes(): array
    {
        return [
            'purchaseOrderId' => (int) $this->integer('purchaseOrderId'),
            'notes' => $this->filled('notes') ? $this->string('notes')->toString() : null,
            'items' => collect($this->input('items', []))->map(fn (array $item) => [
                'purchaseOrderItemId' => (int) $item['purchaseOrderItemId'],
                'qty' => (string) $item['qty'],
            ])->all(),
        ];
    }
}
