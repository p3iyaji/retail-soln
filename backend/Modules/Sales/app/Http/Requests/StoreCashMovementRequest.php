<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Enums\CashMovementType;
use Modules\Sales\Http\Concerns\ResolvesPosStore;

class StoreCashMovementRequest extends FormRequest
{
    use ResolvesPosStore;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pos.session') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in([CashMovementType::CashIn->value, CashMovementType::CashOut->value])],
            'amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function storeId(): int
    {
        return $this->resolvePosStoreId($this);
    }

    public function movementType(): CashMovementType
    {
        return CashMovementType::from($this->validated('type'));
    }

    public function amount(): string
    {
        return bcadd((string) $this->validated('amount'), '0', 4);
    }

    public function notes(): ?string
    {
        $notes = $this->validated('notes');

        return is_string($notes) && $notes !== '' ? $notes : null;
    }
}
