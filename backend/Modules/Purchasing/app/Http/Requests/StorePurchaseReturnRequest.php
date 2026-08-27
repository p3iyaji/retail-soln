<?php

namespace Modules\Purchasing\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;

class StorePurchaseReturnRequest extends FormRequest
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
        $goodsReceiptId = (int) $this->input('goodsReceiptId');

        return [
            'goodsReceiptId' => [
                'required',
                'integer',
                Rule::exists('goods_receipts', 'id')->where('organization_id', $organizationId),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.goodsReceiptItemId' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('goods_receipt_items', 'id')->where(function ($query) use ($organizationId, $goodsReceiptId) {
                    $query->where('organization_id', $organizationId)
                        ->where('goods_receipt_id', $goodsReceiptId);
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
     *   goodsReceiptId: int,
     *   notes: ?string,
     *   items: list<array{goodsReceiptItemId: int, qty: string}>
     * }
     */
    public function purchaseReturnAttributes(): array
    {
        return [
            'goodsReceiptId' => (int) $this->integer('goodsReceiptId'),
            'notes' => $this->filled('notes') ? $this->string('notes')->toString() : null,
            'items' => collect($this->input('items', []))->map(fn (array $item) => [
                'goodsReceiptItemId' => (int) $item['goodsReceiptItemId'],
                'qty' => (string) $item['qty'],
            ])->all(),
        ];
    }
}
