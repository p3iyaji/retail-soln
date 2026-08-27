<?php

namespace Modules\Purchasing\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Purchasing\Services\SupplierInvoiceService;

class StoreSupplierPaymentRequest extends FormRequest
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
            'supplierInvoiceId' => [
                'required',
                'integer',
                Rule::exists('supplier_invoices', 'id')->where('organization_id', $organizationId),
            ],
            'amount' => [
                'required',
                'regex:'.SupplierInvoiceService::MONEY_PATTERN,
                $this->positiveDecimalRule(),
            ],
            'method' => ['nullable', 'string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:128'],
            'paidAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
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
     *   supplierInvoiceId: int,
     *   amount: string,
     *   method: ?string,
     *   reference: ?string,
     *   paidAt: ?string,
     *   notes: ?string
     * }
     */
    public function paymentAttributes(): array
    {
        return [
            'supplierInvoiceId' => (int) $this->integer('supplierInvoiceId'),
            'amount' => (string) $this->input('amount'),
            'method' => $this->filled('method') ? $this->string('method')->toString() : null,
            'reference' => $this->filled('reference') ? $this->string('reference')->toString() : null,
            'paidAt' => $this->filled('paidAt') ? $this->string('paidAt')->toString() : null,
            'notes' => $this->filled('notes') ? $this->string('notes')->toString() : null,
        ];
    }
}
