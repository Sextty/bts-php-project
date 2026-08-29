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
            'montant_eqp' => ['nullable', 'numeric', 'min:0'],
            'montant_fdr' => ['nullable', 'numeric', 'min:0'],
            'montant_amg' => ['nullable', 'numeric', 'min:0'],
            'montant_chp' => ['nullable', 'numeric', 'min:0'],
            'nombre_credits_sollicites' => ['required', 'integer', 'min:1', 'max:100'],
            'unite_depot' => ['required', 'string', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $global = (float) $this->input('montant_global_sollicite', 0);
            $eqp = (float) $this->input('montant_eqp', 0);
            $fdr = (float) $this->input('montant_fdr', 0);
            $amg = (float) $this->input('montant_amg', 0);
            $chp = (float) $this->input('montant_chp', 0);

            $sum = $eqp + $fdr + $amg + $chp;

            // If any breakdown amount is specified, the sum must match the global amount
            if ($sum > 0 && abs($sum - $global) > 0.01) {
                $validator->errors()->add(
                    'montant_global_sollicite',
                    "La somme des financements détaillés (Équipement: {$eqp} TND + Fonds de Roulement: {$fdr} TND + Aménagement: {$amg} TND + Cheptel: {$chp} TND = {$sum} TND) doit être exactement égale au montant global sollicité ({$global} TND)."
                );
            }
        });
    }
}
