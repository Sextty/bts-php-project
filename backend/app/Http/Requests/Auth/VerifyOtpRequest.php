<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared shape for every OTP-verify step (registration, login, Google phone-verification) — the
 * pre_auth_token identifies which user/purpose, so there is nothing else to validate here.
 */
class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pre_auth_token' => ['required', 'string'],
            'otp_code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }
}
