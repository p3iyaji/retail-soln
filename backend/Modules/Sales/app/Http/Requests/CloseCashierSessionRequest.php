<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sales\Http\Concerns\ResolvesPosStore;

class CloseCashierSessionRequest extends FormRequest
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
            'closingCash' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function storeId(): int
    {
        return $this->resolvePosStoreId($this);
    }

    public function closingCash(): string
    {
        return bcadd((string) $this->validated('closingCash'), '0', 4);
    }

    public function notes(): ?string
    {
        $notes = $this->validated('notes');

        return is_string($notes) && $notes !== '' ? $notes : null;
    }
}
