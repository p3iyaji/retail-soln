<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Enums\InstallmentPlanMode;
use Modules\Sales\Enums\PaymentMethod;
use Modules\Sales\Enums\ProformaConvertTarget;

class ConvertProformaInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user?->hasPermission('invoices.proforma.convert') !== true) {
            return false;
        }

        if ($this->has('installmentPlan') && $user->hasPermission('sales.installments.create') !== true) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(OrganizationService::class)->current()->id;
        $target = $this->string('target')->toString();
        $requiresSale = in_array($target, [ProformaConvertTarget::Sale->value, ProformaConvertTarget::Both->value], true);

        return [
            'target' => ['required', 'string', Rule::in(ProformaConvertTarget::values())],
            'uuid' => [Rule::requiredIf($requiresSale), 'nullable', 'uuid'],
            'sessionId' => [
                Rule::requiredIf($requiresSale),
                'nullable',
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
            'installmentPlan' => ['sometimes', 'array'],
            'installmentPlan.mode' => ['required_with:installmentPlan', 'string', Rule::in(InstallmentPlanMode::values())],
            'installmentPlan.installments' => ['required_with:installmentPlan', 'array', 'min:1'],
            'installmentPlan.installments.*.sequence' => ['required', 'integer', 'min:1'],
            'installmentPlan.installments.*.amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gt:0'],
            'installmentPlan.installments.*.dueAt' => ['required', 'date'],
            'payments' => [Rule::requiredIf($requiresSale), 'array', 'min:1'],
            'payments.*.method' => ['required', 'string', Rule::in(PaymentMethod::values())],
            'payments.*.amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gt:0'],
            'payments.*.provider' => ['required', 'string', 'max:64'],
            'payments.*.reference' => ['nullable', 'string', 'max:128'],
            'honorPrices' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{
     *   target: string,
     *   uuid?: string,
     *   sessionId?: int,
     *   terminalId?: ?int,
     *   payments?: list<array{method: string, amount: string, provider: string, reference?: ?string}>,
     *   installmentPlan?: ?array{
     *     mode: string,
     *     installments: list<array{sequence: int, amount: string, dueAt: string}>,
     *   },
     *   honorPrices?: bool,
     *   idempotencyKey: string,
     * }
     */
    public function convertAttributes(): array
    {
        $validated = $this->validated();

        $attrs = [
            'target' => $validated['target'],
            'idempotencyKey' => (string) $this->header('Idempotency-Key'),
        ];

        if (isset($validated['uuid'])) {
            $attrs['uuid'] = $validated['uuid'];
        }
        if (isset($validated['sessionId'])) {
            $attrs['sessionId'] = (int) $validated['sessionId'];
        }
        if (array_key_exists('terminalId', $validated)) {
            $attrs['terminalId'] = $validated['terminalId'] !== null ? (int) $validated['terminalId'] : null;
        }
        if (isset($validated['payments'])) {
            $attrs['payments'] = collect($validated['payments'])->map(fn (array $payment) => [
                'method' => $payment['method'],
                'amount' => bcadd((string) $payment['amount'], '0', 4),
                'provider' => $payment['provider'],
                'reference' => $payment['reference'] ?? null,
            ])->all();
        }
        if (isset($validated['installmentPlan'])) {
            $attrs['installmentPlan'] = [
                'mode' => $validated['installmentPlan']['mode'],
                'installments' => collect($validated['installmentPlan']['installments'])->map(fn (array $line) => [
                    'sequence' => (int) $line['sequence'],
                    'amount' => bcadd((string) $line['amount'], '0', 4),
                    'dueAt' => $line['dueAt'],
                ])->all(),
            ];
        }
        if (array_key_exists('honorPrices', $validated)) {
            $attrs['honorPrices'] = (bool) $validated['honorPrices'];
        }

        return $attrs;
    }

    protected function prepareForValidation(): void
    {
        if ($this->header('Idempotency-Key') === null || $this->header('Idempotency-Key') === '') {
            $this->merge([]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $key = $this->header('Idempotency-Key');
            if ($key === null || $key === '') {
                $validator->errors()->add('idempotencyKey', 'Idempotency-Key header is required.');

                return;
            }

            if (strlen($key) > 64) {
                $validator->errors()->add('idempotencyKey', 'Idempotency-Key must be at most 64 characters.');
            }
        });
    }
}
