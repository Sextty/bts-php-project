<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TelegramLinkStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pre_auth_token' => ['required', 'string'],
            // Which flow the caller is in — the pre-auth token is scoped to one purpose, so this
            // must match what issued it.
            'purpose' => ['required', 'string', 'in:registration,login'],
        ];
    }
}
