import { apiFetch } from '@/lib/api/client';

export interface AppointmentBranchDto {
  name: string;
  address: string;
  ville: string;
  google_maps_url: string;
}

export interface AppointmentDto {
  id: number;
  attempt_number: number;
  max_attempts: number;
  scheduled_date: string;
  scheduled_time: string;
  status: 'proposed' | 'accepted' | 'rejected';
  decided_at: string | null;
  branch?: AppointmentBranchDto;
}

export function getAppointment(applicationId: number) {
  return apiFetch<{ appointment: AppointmentDto }>(`/applications/${applicationId}/appointment`, { auth: true });
}

export function acceptAppointment(applicationId: number) {
  return apiFetch<{ appointment: AppointmentDto }>(`/applications/${applicationId}/appointment/accept`, {
    method: 'POST',
    auth: true,
  });
}

export function rejectAppointment(applicationId: number) {
  return apiFetch<{ application_status: string; appointment: AppointmentDto | null }>(
    `/applications/${applicationId}/appointment/reject`,
    { method: 'POST', auth: true }
  );
}
