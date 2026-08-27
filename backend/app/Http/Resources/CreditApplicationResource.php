<?php

namespace App\Http\Resources;

use App\Models\StaffUser;
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
            // Review notes are internal. Staff APIs retain them; customer APIs do not expose them.
            'rejection_reason' => $request->user() instanceof StaffUser ? $this->rejection_reason : null,
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
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'ville' => $this->branch->ville,
                'governorate' => $this->branch->ville,
                'address' => $this->branch->address,
                'google_maps_url' => $this->branch->googleMapsUrl(),
            ]),
            'latest_appointment' => $this->whenLoaded('appointments', function () {
                $latest = $this->relationLoaded('appointments')
                    ? $this->appointments->sortByDesc('attempt_number')->first()
                    : $this->latestAppointment();

                $latest?->loadMissing('branch');

                return $latest ? new AppointmentResource($latest) : null;
            }),
            'appointments' => AppointmentResource::collection($this->whenLoaded('appointments')),
            'report_messages_count' => $this->report_messages_count ?? $this->reportMessages()->count(),
            'is_report_closed' => $this->isReportClosed(),
            'report_closed_at' => $this->report_closed_at,
            'report_closed_reason' => $this->report_closed_reason,
        ];
    }
}
