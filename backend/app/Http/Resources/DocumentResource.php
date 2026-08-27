<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'malware_scan_status' => $this->malware_scan_status,
            'uploaded_at' => $this->created_at,
            'ai_verified_at' => $this->ai_verified_at,
            'ai_is_valid' => $this->ai_is_valid,
            'ai_confidence' => $this->ai_confidence,
            'ai_comment' => $this->ai_comment,
            'ai_extracted_fields' => $this->ai_extracted_fields,
            'ai_mismatches' => $this->ai_mismatches,
            'ai_processing_status' => $this->ai_processing_status,
            'ai_detected_issues' => $this->ai_detected_issues,
            'ai_requires_human_review' => $this->ai_requires_human_review,
        ];
    }
}
