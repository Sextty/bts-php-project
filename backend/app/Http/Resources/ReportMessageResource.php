<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_type' => $this->sender_type,
            'sender_name' => $this->sender_type === 'staff'
                ? ($this->staffUser ? "{$this->staffUser->first_name} {$this->staffUser->last_name}" : 'BTS Bank')
                : ($this->user ? "{$this->user->first_name} {$this->user->last_name}" : 'You'),
            'body' => $this->body,
            'created_at' => $this->created_at,
        ];
    }
}
