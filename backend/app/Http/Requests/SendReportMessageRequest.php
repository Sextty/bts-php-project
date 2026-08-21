<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by the customer and staff report controllers — both accept a plain-text message body
 * with the same rules; before this request class each controller re-declared the identical
 * inline validation.
 */
class SendReportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:2000', 'required_without:file'],
            'file' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:pdf,jpg,jpeg,png,gif,webp,doc,docx,xls,xlsx,csv,txt,ppt,pptx',
                'extensions:pdf,jpg,jpeg,png,gif,webp,doc,docx,xls,xlsx,csv,txt,ppt,pptx',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required_without' => 'Veuillez saisir un message ou joindre un fichier.',
            'body.max' => 'Le message ne doit pas dépasser 2000 caractères.',
            'file.file' => 'Le fichier sélectionné est invalide.',
            'file.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
            'file.mimes' => 'Format de fichier non supporté. Formats acceptés : PDF, JPG, PNG, WEBP, Word, Excel.',
            'file.extensions' => 'Extension de fichier non supportée. Formats acceptés : PDF, JPG, PNG, WEBP, DOC, DOCX, XLS, XLSX.',
        ];
    }
}
