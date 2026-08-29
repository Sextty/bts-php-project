<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_number' => $this->attempt_number,
            'max_attempts' => Appointment::MAX_ATTEMPTS,
            'reschedule_count' => $this->rescheduleCount(),
            'max_reschedules' => Appointment::MAX_RESCHEDULES,
            'remaining_reschedules' => $this->remainingReschedules(),
            'can_self_reschedule' => $this->canSelfReschedule(),
            'scheduled_date' => $this->scheduled_date,
            'scheduled_time' => $this->scheduled_time,
            'status' => $this->status,
            'is_auto_scheduled_future' => (bool) $this->is_auto_scheduled_future,
            'decided_at' => $this->decided_at,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'address' => $this->branch->address,
                'ville' => $this->branch->ville,
                'governorate' => $this->branch->ville,
                'google_maps_url' => $this->branch->googleMapsUrl(),
            ]),
        ];
    }
}
