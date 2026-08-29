<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;

class StoreProformaInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('invoices.proforma.create') === true;
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
                Rule::exists('stores', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'customerId' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)->where('is_walk_in', false)
                ),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'validUntil' => ['nullable', 'date', 'after_or_equal:today'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variantId' => [
                'required',
                'integer',
                Rule::exists('product_variants', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'items.*.qty' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gt:0'],
            'items.*.unitPrice' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gte:0'],
            'items.*.discount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gte:0'],
        ];
    }

    /**
     * @return array{
     *   storeId: int,
     *   customerId: int,
     *   notes?: ?string,
     *   validUntil?: ?string,
     *   items: list<array{variantId: int, qty: string, unitPrice: string, discount: string}>,
     * }
     */
    public function proformaAttributes(): array
    {
        $validated = $this->validated();

        return [
            'storeId' => (int) $validated['storeId'],
            'customerId' => (int) $validated['customerId'],
            'notes' => $validated['notes'] ?? null,
            'validUntil' => $validated['validUntil'] ?? null,
            'items' => collect($validated['items'])->map(fn (array $item) => [
                'variantId' => (int) $item['variantId'],
                'qty' => bcadd((string) $item['qty'], '0', 4),
                'unitPrice' => bcadd((string) $item['unitPrice'], '0', 4),
                'discount' => bcadd((string) $item['discount'], '0', 4),
            ])->all(),
        ];
    }
}
