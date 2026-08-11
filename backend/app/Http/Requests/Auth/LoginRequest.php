<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Email or phone — LoginController checks both columns.
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
