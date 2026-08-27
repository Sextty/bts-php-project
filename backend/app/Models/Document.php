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
        'malware_scan_status',
        'malware_signature',
        'malware_scanned_at',
        'ai_verified_at',
        'ai_is_valid',
        'ai_confidence',
        'ai_comment',
        'ai_extracted_fields',
        'ai_mismatches',
        'ai_processing_status',
        'ai_detected_issues',
        'ai_requires_human_review',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'malware_scanned_at' => 'datetime',
            'ai_verified_at' => 'datetime',
            'ai_is_valid' => 'boolean',
            'ai_extracted_fields' => 'array',
            'ai_mismatches' => 'array',
            'ai_detected_issues' => 'array',
            'ai_requires_human_review' => 'boolean',
        ];
    }

    public function creditApplication(): BelongsTo
    {
        return $this->belongsTo(CreditApplication::class);
    }
}
