<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Enums\PaymentMethod;
use Modules\Sales\Http\Concerns\ResolvesPosStore;

class StoreSaleRequest extends FormRequest
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
            'installmentPlan' => ['prohibited'],
            'sessionId' => [
                'required',
                'integer',
                Rule::exists('cashier_sessions', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'terminalId' => [
                'nullable',
                'integer',
                Rule::exists('terminals', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
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
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'string', Rule::in(PaymentMethod::values())],
            'payments.*.amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gt:0'],
            'payments.*.provider' => ['required', 'string', Rule::in(['manual'])],
            'payments.*.reference' => ['nullable', 'string', 'max:128'],
        ];
    }

    /**
     * @return array{
     *   uuid: string,
     *   customerId: ?int,
     *   sessionId: int,
     *   terminalId: ?int,
     *   items: list<array{variantId: int, qty: string, unitPrice: string, discount: string}>,
     *   payments: list<array{method: string, amount: string, provider: string, reference?: ?string}>,
     * }
     */
    public function saleAttributes(): array
    {
        $validated = $this->validated();

        return [
            'uuid' => $validated['uuid'],
            'customerId' => isset($validated['customerId']) ? (int) $validated['customerId'] : null,
            'sessionId' => (int) $validated['sessionId'],
            'terminalId' => isset($validated['terminalId']) ? (int) $validated['terminalId'] : null,
            'items' => collect($validated['items'])->map(fn (array $item) => [
                'variantId' => (int) $item['variantId'],
                'qty' => bcadd((string) $item['qty'], '0', 4),
                'unitPrice' => bcadd((string) $item['unitPrice'], '0', 4),
                'discount' => bcadd((string) $item['discount'], '0', 4),
            ])->all(),
            'payments' => collect($validated['payments'])->map(fn (array $payment) => [
                'method' => $payment['method'],
                'amount' => bcadd((string) $payment['amount'], '0', 4),
                'provider' => $payment['provider'],
                'reference' => $payment['reference'] ?? null,
            ])->all(),
        ];
    }

    public function storeId(): int
    {
        return $this->resolvePosStoreId($this);
    }
}
