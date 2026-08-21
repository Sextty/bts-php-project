<?php

namespace App\Http\Requests\CreditApplication;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Étape 2 — Demande de Crédit. n_demande and identifiant_personne are deliberately NOT
 * validated input fields here — n_demande is server-generated (ApplicationNumberService) and
 * identifiant_personne is derived from the client's code_client. Any client-supplied values
 * for these fields are ignored by CreditApplicationService::saveCreditRequest.
 */
class UpdateCreditRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    public function rules(): array
    {
        return [
            'identifiant_personne' => ['nullable', 'string', 'max:50'],
            'nom_ou_rs' => ['required', 'string', 'max:150'],
            'prenom_ou_dc' => ['required', 'string', 'max:150'],
            'origine' => ['required', 'string', 'max:100'],
            'date_depot' => ['required', 'date', 'before_or_equal:today'],
            'date_reception' => ['required', 'date', 'after_or_equal:date_depot'],
            'type_demande' => ['required', 'string', 'max:100'],
            'code_devise' => ['required', 'string', 'size:3'],
            'montant_global_sollicite' => ['required', 'numeric', 'min:0.001'],
            'nombre_credits_sollicites' => ['required', 'integer', 'min:1', 'max:100'],
            'unite_depot' => ['required', 'string', 'max:100'],
        ];
    }
}
