<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sales\Http\Concerns\ResolvesPosStore;

class CurrentCashierSessionRequest extends FormRequest
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
        ];
    }

    public function storeId(): int
    {
        return $this->resolvePosStoreId($this);
    }

    public function terminalName(): string
    {
        return trim($this->query('terminalName', ''));
    }
}
