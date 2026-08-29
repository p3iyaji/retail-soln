<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;

class UpdateProformaInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('invoices.proforma.update') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'validUntil' => ['sometimes', 'nullable', 'date'],
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
     *   notes?: ?string,
     *   validUntil?: ?string,
     *   items: list<array{variantId: int, qty: string, unitPrice: string, discount: string}>,
     * }
     */
    public function proformaAttributes(): array
    {
        $validated = $this->validated();

        $attrs = [
            'items' => collect($validated['items'])->map(fn (array $item) => [
                'variantId' => (int) $item['variantId'],
                'qty' => bcadd((string) $item['qty'], '0', 4),
                'unitPrice' => bcadd((string) $item['unitPrice'], '0', 4),
                'discount' => bcadd((string) $item['discount'], '0', 4),
            ])->all(),
        ];

        if (array_key_exists('notes', $validated)) {
            $attrs['notes'] = $validated['notes'];
        }
        if (array_key_exists('validUntil', $validated)) {
            $attrs['validUntil'] = $validated['validUntil'];
        }

        return $attrs;
    }
}
