<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasAttachment = !empty($this->attachment_path);
        $isStaff = $request->user() instanceof \App\Models\StaffUser;

        return [
            'id' => $this->id,
            'sender_type' => $this->sender_type,
            'sender_name' => $this->sender_type === 'staff'
                ? ($this->staffUser ? "{$this->staffUser->first_name} {$this->staffUser->last_name}" : 'BTS Bank')
                : ($this->user ? "{$this->user->first_name} {$this->user->last_name}" : 'Client'),
            'body' => $this->body,
            'has_attachment' => $hasAttachment,
            'attachment_url' => $hasAttachment
                ? ($isStaff
                    ? "/api/staff/reports/{$this->credit_application_id}/messages/{$this->id}/attachment"
                    : "/api/applications/{$this->credit_application_id}/report/messages/{$this->id}/attachment")
                : null,
            'attachment_name' => $this->attachment_name,
            'attachment_type' => $this->attachment_type,
            'attachment_size' => $this->attachment_size,
            'created_at' => $this->created_at,
        ];
    }
}
