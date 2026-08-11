<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditRequest extends Model
{
    protected $fillable = [
        'credit_application_id',
        'n_demande',
        'identifiant_personne',
        'nom_ou_rs',
        'prenom_ou_dc',
        'type_pid',
        'numero_pid',
        'origine',
        'date_depot',
        'date_reception',
        'type_demande',
        'code_devise',
        'montant_global_sollicite',
        'nombre_credits_sollicites',
        'unite_depot',
    ];

    protected function casts(): array
    {
        return [
            'date_depot' => 'date',
            'date_reception' => 'date',
            'montant_global_sollicite' => 'decimal:3',
            'nombre_credits_sollicites' => 'integer',
        ];
    }

    public function creditApplication(): BelongsTo
    {
        return $this->belongsTo(CreditApplication::class);
    }
}
