<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Client extends Model
{
    protected $fillable = [
        'credit_application_id',
        'code_client',
        'civilite',
        'nom',
        'prenom',
        'nom_epoux',
        'deuxieme_prenom',
        'date_naissance',
        'lieu_naissance',
        'pays_naissance',
        'nationalite',
        'pays_residence',
        'etat_civil',
        'nombre_enfants',
        'type_pid',
        'numero_pid',
        'date_delivrance_pid',
        'lieu_delivrance_pid',
        'numero_carte_sejour',
        'profession',
        'date_entree_relation',
    ];

    protected function casts(): array
    {
        return [
            'date_naissance' => 'date',
            'date_delivrance_pid' => 'date',
            'date_entree_relation' => 'date',
            'nombre_enfants' => 'integer',
        ];
    }

    public function creditApplication(): BelongsTo
    {
        return $this->belongsTo(CreditApplication::class);
    }
}
