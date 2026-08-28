<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Enums\PaymentMethod;
use Modules\Sales\Enums\SaleReturnReason;
use Modules\Sales\Http\Concerns\ResolvesPosStore;

class StoreSaleReturnRequest extends FormRequest
{
    use ResolvesPosStore;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sales.refund') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'uuid' => ['required', 'uuid'],
            'saleId' => [
                'required',
                'integer',
                Rule::exists('sales', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'sessionId' => [
                'nullable',
                'integer',
                Rule::exists('cashier_sessions', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'reason' => ['required', 'string', Rule::in(SaleReturnReason::values())],
            'items' => ['required', 'array', 'min:1'],
            'items.*.saleItemId' => [
                'required',
                'integer',
                Rule::exists('sale_items', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'items.*.qty' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gt:0'],
            'refunds' => ['required', 'array', 'min:1'],
            'refunds.*.method' => ['required', 'string', Rule::in(PaymentMethod::values())],
            'refunds.*.amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gt:0'],
            'refunds.*.provider' => ['required', 'string', Rule::in(['manual'])],
            'refunds.*.reference' => ['nullable', 'string', 'max:128'],
        ];
    }

    /**
     * @return array{
     *   uuid: string,
     *   saleId: int,
     *   sessionId: ?int,
     *   reason: string,
     *   items: list<array{saleItemId: int, qty: string}>,
     *   refunds: list<array{method: string, amount: string, provider: string, reference?: ?string}>,
     * }
     */
    public function returnAttributes(): array
    {
        $validated = $this->validated();

        return [
            'uuid' => $validated['uuid'],
            'saleId' => (int) $validated['saleId'],
            'sessionId' => isset($validated['sessionId']) ? (int) $validated['sessionId'] : null,
            'reason' => $validated['reason'],
            'items' => collect($validated['items'])->map(fn (array $item) => [
                'saleItemId' => (int) $item['saleItemId'],
                'qty' => bcadd((string) $item['qty'], '0', 4),
            ])->all(),
            'refunds' => collect($validated['refunds'])->map(fn (array $refund) => [
                'method' => $refund['method'],
                'amount' => bcadd((string) $refund['amount'], '0', 4),
                'provider' => $refund['provider'],
                'reference' => $refund['reference'] ?? null,
            ])->all(),
        ];
    }

    public function storeId(): int
    {
        return $this->resolvePosStoreId($this);
    }
}
