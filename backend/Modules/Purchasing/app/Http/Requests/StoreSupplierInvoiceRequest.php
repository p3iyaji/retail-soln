<?php

namespace Modules\Purchasing\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Purchasing\Services\SupplierInvoiceService;

class StoreSupplierInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchase.pay') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'goodsReceiptId' => [
                'required',
                'integer',
                Rule::exists('goods_receipts', 'id')->where('organization_id', $organizationId),
            ],
            'totalAmount' => [
                'sometimes',
                'nullable',
                'regex:'.SupplierInvoiceService::MONEY_PATTERN,
                $this->positiveMoneyRule(),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function positiveMoneyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            if (! is_scalar($value) || bccomp((string) $value, '0', 4) <= 0) {
                $fail("The {$attribute} must be greater than zero.");
            }
        };
    }

    /**
     * @return array{goodsReceiptId: int, totalAmount: ?string, notes: ?string}
     */
    public function invoiceAttributes(): array
    {
        return [
            'goodsReceiptId' => (int) $this->integer('goodsReceiptId'),
            'totalAmount' => $this->filled('totalAmount') ? (string) $this->input('totalAmount') : null,
            'notes' => $this->filled('notes') ? $this->string('notes')->toString() : null,
        ];
    }
}
