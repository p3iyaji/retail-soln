<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sales\Http\Concerns\ResolvesPosStore;

class OpenCashierSessionRequest extends FormRequest
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
            'terminalName' => ['required', 'string', 'max:64'],
            'openingFloat' => ['required', 'regex:/^\d+(\.\d{1,4})?$/', 'numeric', 'gte:0'],
        ];
    }

    public function storeId(): int
    {
        return $this->resolvePosStoreId($this);
    }

    public function terminalName(): string
    {
        return trim($this->validated('terminalName'));
    }

    public function openingFloat(): string
    {
        return bcadd((string) $this->validated('openingFloat'), '0', 4);
    }
}
