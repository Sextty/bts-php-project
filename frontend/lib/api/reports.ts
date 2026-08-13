import { apiFetch } from '@/lib/api/client';
import type { StaffApplicationDto } from '@/lib/api/staff';

export interface ReportMessageDto {
  id: number;
  sender_type: 'customer' | 'staff';
  sender_name: string;
  body: string;
  created_at: string;
}

export function getReportMessages(applicationId: number) {
  return apiFetch<{ messages: ReportMessageDto[] }>(`/applications/${applicationId}/report/messages`, { auth: true });
}

export function sendReportMessage(applicationId: number, body: string) {
  return apiFetch<{ message: ReportMessageDto }>(`/applications/${applicationId}/report/messages`, {
    method: 'POST',
    body: { body },
    auth: true,
  });
}

export function listStaffReports() {
  return apiFetch<{ applications: StaffApplicationDto[]; meta: { current_page: number; last_page: number; total: number } }>(
    '/staff/reports',
    { auth: 'staff' }
  );
}

export function getStaffReportMessages(applicationId: number) {
  return apiFetch<{ messages: ReportMessageDto[] }>(`/staff/reports/${applicationId}/messages`, { auth: 'staff' });
}

export function sendStaffReportMessage(applicationId: number, body: string) {
  return apiFetch<{ message: ReportMessageDto }>(`/staff/reports/${applicationId}/messages`, {
    method: 'POST',
    body: { body },
    auth: 'staff',
  });
}
