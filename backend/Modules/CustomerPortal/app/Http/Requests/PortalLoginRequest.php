<?php

namespace Modules\CustomerPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortalLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
