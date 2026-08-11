<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    protected $fillable = [
        'credit_application_id',
        'code_projet',
        'identifiant_personne',
        'nom_ou_rs',
        'prenom_ou_dc',
        'type_projet',
        'objet',
        'adresse',
        'ville',
        'code_postal',
        'activite',
        'description',
        'delegation',
        'localisation',
        'cout',
        'investissement_personnel',
        'financement',
        'revenus',
        'depenses',
    ];

    protected function casts(): array
    {
        return [
            'cout' => 'decimal:3',
            'investissement_personnel' => 'decimal:3',
            'financement' => 'decimal:3',
            'revenus' => 'decimal:3',
            'depenses' => 'decimal:3',
        ];
    }

    public function creditApplication(): BelongsTo
    {
        return $this->belongsTo(CreditApplication::class);
    }
}
