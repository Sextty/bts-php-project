<?php

namespace App\Http\Requests\CreditApplication;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Étape 3 — Informations Projet. code_projet and identifiant_personne are deliberately NOT
 * validated input fields here — code_projet is server-generated (ApplicationNumberService) and
 * identifiant_personne is derived from the client's code_client. Any client-supplied values
 * for these fields are ignored by CreditApplicationService::saveProject.
 */
class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    public function rules(): array
    {
        return [
            'code_projet' => ['nullable', 'string', 'max:50'],
            'identifiant_personne' => ['nullable', 'string', 'max:50'],
            'nom_ou_rs' => ['nullable', 'string', 'max:150'],
            'prenom_ou_dc' => ['nullable', 'string', 'max:150'],
            'type_projet' => ['required', 'string', 'max:100'],
            'objet' => ['required', 'string', 'max:255'],
            'adresse' => ['required', 'string', 'max:255'],
            'ville' => ['required', 'string', 'max:100'],
            'code_postal' => ['required', 'string', 'max:20'],
            'activite' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            'delegation' => ['required', 'string', 'max:150'],
            'localisation' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'cout' => ['required', 'numeric', 'min:0.001'],
            'investissement_personnel' => ['required', 'numeric', 'min:0'],
            'financement' => ['nullable', 'numeric', 'min:0'],
            'revenus' => ['required', 'numeric', 'min:0'],
            'depenses' => ['required', 'numeric', 'min:0'],
        ];
    }
}
