<?php

namespace App\Http\Requests\CreditApplication;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', Rule::in(array_keys(config('credit_documents.types', [])))],
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', config('credit_documents.allowed_mimes', [])),
                'max:'.config('credit_documents.max_size_kb', 10240),
            ],
        ];
    }
}
