<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The ONLY accepted input — never a client-asserted email/name/profile.
            'google_id_token' => ['required', 'string'],
        ];
    }
}
