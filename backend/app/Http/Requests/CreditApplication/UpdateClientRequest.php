<?php

namespace App\Http\Requests\CreditApplication;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Étape 1 — Client / Personne Physique. Spec: "The user cannot continue to Step 2 until all
 * required fields... are valid" — so this enforces the full field set as required on every
 * save of this step, not a partial-autosave shape. A few fields are conditionally optional by
 * nature (nom_epoux only applies if married, deuxieme_prenom is genuinely optional,
 * numero_carte_sejour only applies to foreign residents).
 */
class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    public function rules(): array
    {
        return [
            'code_client' => ['required', 'string', 'max:50'],
            'civilite' => ['required', 'string', 'in:M,Mme,Mlle'],
            'nom' => ['required', 'string', 'max:150'],
            'prenom' => ['required', 'string', 'max:150'],
            'nom_epoux' => ['nullable', 'string', 'max:150'],
            'deuxieme_prenom' => ['nullable', 'string', 'max:150'],
            'date_naissance' => ['required', 'date', 'before:today'],
            'lieu_naissance' => ['required', 'string', 'max:150'],
            'pays_naissance' => ['required', 'string', 'max:100'],
            'nationalite' => ['required', 'string', 'max:100'],
            'pays_residence' => ['required', 'string', 'max:100'],
            'etat_civil' => ['required', 'string', 'in:célibataire,marié,divorcé,veuf'],
            'nombre_enfants' => ['required', 'integer', 'min:0', 'max:30'],
            'type_pid' => ['required', 'string', 'in:CIN,Passeport,Carte de séjour'],
            'numero_pid' => ['required', 'string', 'max:50'],
            'date_delivrance_pid' => ['required', 'date', 'before_or_equal:today'],
            'lieu_delivrance_pid' => ['required', 'string', 'max:150'],
            'numero_carte_sejour' => ['nullable', 'string', 'max:50'],
            'profession' => ['required', 'string', 'max:150'],
            'date_entree_relation' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
