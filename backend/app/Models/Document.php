<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'credit_application_id',
        'document_type',
        'original_filename',
        'disk_path',
        'mime_type',
        'size_bytes',
        'ai_verified_at',
        'ai_is_valid',
        'ai_confidence',
        'ai_comment',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'ai_verified_at' => 'datetime',
            'ai_is_valid' => 'boolean',
        ];
    }

    public function creditApplication(): BelongsTo
    {
        return $this->belongsTo(CreditApplication::class);
    }
}
