<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Enums\PaymentMethod;
use Modules\Sales\Http\Concerns\ResolvesPosStore;

class CollectInstallmentPaymentRequest extends FormRequest
{
    use ResolvesPosStore;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sales.installments.collect') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        return [
            'installmentId' => [
                'required',
                'integer',
                Rule::exists('installments', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'sessionId' => [
                'required',
                'integer',
                Rule::exists('cashier_sessions', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)
                ),
            ],
            'payment' => ['required', 'array'],
            'payment.method' => ['required', 'string', Rule::in(PaymentMethod::values())],
            'payment.amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gt:0'],
            'payment.provider' => ['required', 'string', Rule::in(['manual'])],
            'payment.reference' => ['nullable', 'string', 'max:128'],
        ];
    }

    /**
     * @return array{
     *   installmentId: int,
     *   sessionId: int,
     *   payment: array{method: string, amount: string, provider: string, reference?: ?string},
     * }
     */
    public function collectionAttributes(): array
    {
        $validated = $this->validated();

        return [
            'installmentId' => (int) $validated['installmentId'],
            'sessionId' => (int) $validated['sessionId'],
            'payment' => [
                'method' => $validated['payment']['method'],
                'amount' => bcadd((string) $validated['payment']['amount'], '0', 4),
                'provider' => $validated['payment']['provider'],
                'reference' => $validated['payment']['reference'] ?? null,
            ],
        ];
    }

    public function storeId(): int
    {
        return $this->resolvePosStoreId($this);
    }
}
