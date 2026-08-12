<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'is_locked' => $this->isLocked(),
            'submitted_at' => $this->submitted_at,
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at,
            'applicant' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => "{$this->user->first_name} {$this->user->last_name}",
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ]),
            'client' => $this->whenLoaded('client'),
            'credit_request' => $this->whenLoaded('creditRequest'),
            'project' => $this->whenLoaded('project'),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'validation_steps' => $this->whenLoaded('validationSteps', fn () => $this->validationSteps->map(fn ($step) => [
                'step' => $step->step,
                'status' => $step->status,
                'errors' => $step->errors,
                'created_at' => $step->created_at,
            ])),
        ];
    }
}
