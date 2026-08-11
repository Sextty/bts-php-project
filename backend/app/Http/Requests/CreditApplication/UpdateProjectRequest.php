<?php

namespace App\Http\Requests\CreditApplication;

use Illuminate\Foundation\Http\FormRequest;

/** Étape 3 — Informations Projet. Field set is exactly the spec's own example list. */
class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    public function rules(): array
    {
        return [
            'code_projet' => ['required', 'string', 'max:50'],
            'identifiant_personne' => ['required', 'string', 'max:50'],
            'nom_ou_rs' => ['required', 'string', 'max:150'],
            'prenom_ou_dc' => ['required', 'string', 'max:150'],
            'type_projet' => ['required', 'string', 'max:100'],
            'objet' => ['required', 'string', 'max:255'],
            'adresse' => ['required', 'string', 'max:255'],
            'ville' => ['required', 'string', 'max:100'],
            'code_postal' => ['required', 'string', 'max:20'],
            'activite' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            'delegation' => ['required', 'string', 'max:150'],
            'localisation' => ['required', 'string', 'max:255'],
            'cout' => ['required', 'numeric', 'min:0.001'],
            'investissement_personnel' => ['required', 'numeric', 'min:0'],
            'financement' => ['required', 'numeric', 'min:0'],
            'revenus' => ['required', 'numeric', 'min:0'],
            'depenses' => ['required', 'numeric', 'min:0'],
        ];
    }
}
