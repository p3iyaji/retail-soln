<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Http\Concerns\ResolvesPosStore;

class StoreHeldSaleRequest extends FormRequest
{
    use ResolvesPosStore;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sales.create') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'uuid' => ['required', 'uuid'],
            'customerId' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
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
     *   uuid: string,
     *   customerId: ?int,
     *   notes: ?string,
     *   items: list<array{variantId: int, qty: string, unitPrice: string, discount: string}>,
     * }
     */
    public function heldSaleAttributes(): array
    {
        $validated = $this->validated();

        return [
            'uuid' => $validated['uuid'],
            'customerId' => isset($validated['customerId']) ? (int) $validated['customerId'] : null,
            'notes' => $validated['notes'] ?? null,
            'items' => collect($validated['items'])->map(fn (array $item) => [
                'variantId' => (int) $item['variantId'],
                'qty' => bcadd((string) $item['qty'], '0', 4),
                'unitPrice' => bcadd((string) $item['unitPrice'], '0', 4),
                'discount' => bcadd((string) $item['discount'], '0', 4),
            ])->all(),
        ];
    }

    public function storeId(): int
    {
        return $this->resolvePosStoreId($this);
    }
}
