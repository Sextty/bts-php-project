import { apiFetch, apiUpload } from '@/lib/api/client';
import type { StaffApplicationDto } from '@/lib/api/staff';

export interface ReportMessageDto {
  id: number;
  sender_type: 'customer' | 'staff';
  sender_name: string;
  body: string;
  has_attachment?: boolean;
  attachment_url?: string | null;
  attachment_name?: string | null;
  attachment_type?: string | null;
  attachment_size?: number | null;
  created_at: string;
}

export interface ReportThreadDto {
  messages: ReportMessageDto[];
  meta?: { has_more: boolean; next_before_id: number | null; limit: number };
  is_closed?: boolean;
  closed_at?: string | null;
  closed_reason?: string | null;
}

export interface BranchDto {
  id: number;
  name: string;
  ville: string;
  delegation?: string | null;
  address: string;
  phone?: string | null;
  fax?: string | null;
  opening_hours?: string | null;
  latitude?: number | string | null;
  longitude?: number | string | null;
}

export interface BannedUserDto {
  id: number;
  first_name: string;
  last_name: string;
  name: string;
  email: string;
  phone?: string | null;
  cin: string;
  status: string;
  banned_at?: string | null;
  banned_reason?: string | null;
  banned_by?: {
    id: number;
    name: string;
    role: string;
  } | null;
  applications_count: number;
}

export function listStaffReports() {
  return apiFetch<{ applications: StaffApplicationDto[]; meta: { current_page: number; last_page: number; total: number } }>(
    '/staff/reports',
    { auth: 'staff' }
  );
}

export function listBranches() {
  return apiFetch<{ branches: BranchDto[] }>('/staff/branches', { auth: 'staff' });
}

export function getStaffReportMessages(applicationId: number, beforeId?: number) {
  const query = beforeId ? `?before_id=${beforeId}` : '';
  return apiFetch<ReportThreadDto>(`/staff/reports/${applicationId}/messages${query}`, { auth: 'staff' });
}

export function sendStaffReportMessage(applicationId: number, body: string) {
  return apiFetch<{ message: ReportMessageDto }>(`/staff/reports/${applicationId}/messages`, {
    method: 'POST',
    body: { body },
    auth: 'staff',
  });
}

export function sendStaffReportMessageWithAttachment(applicationId: number, body?: string, file?: File | null) {
  if (file) {
    const formData = new FormData();
    if (body !== undefined && body !== null) formData.append('body', body);
    formData.append('file', file);
    return apiUpload<{ message: ReportMessageDto }>(`/staff/reports/${applicationId}/messages`, formData);
  }
  return sendStaffReportMessage(applicationId, body || '');
}

export const sendReportMessageWithAttachment = sendStaffReportMessageWithAttachment;

export function closeStaffReport(applicationId: number, reason?: string) {
  return apiFetch<{ application: StaffApplicationDto; message: ReportMessageDto }>(`/staff/reports/${applicationId}/close`, {
    method: 'POST',
    body: { reason },
    auth: 'staff',
  });
}

export function reopenStaffReport(applicationId: number) {
  return apiFetch<{ application: StaffApplicationDto; message: ReportMessageDto }>(`/staff/reports/${applicationId}/reopen`, {
    method: 'POST',
    body: {},
    auth: 'staff',
  });
}

export function banClientFromApplication(applicationId: number, reason: string) {
  return apiFetch<{ user: unknown; message: ReportMessageDto }>(`/staff/reports/${applicationId}/ban-client`, {
    method: 'POST',
    body: { reason },
    auth: 'staff',
  });
}

export function listBannedUsers(search?: string, page: number = 1) {
  const query = new URLSearchParams();
  if (search) query.set('search', search);
  if (page > 1) query.set('page', String(page));
  const queryString = query.toString() ? `?${query.toString()}` : '';

  return apiFetch<{
    banned_users: BannedUserDto[];
    meta: { current_page: number; last_page: number; total: number };
  }>(`/staff/banned-users${queryString}`, { auth: 'staff' });
}

export function unbanUser(userId: number) {
  return apiFetch<{ message: string; user: unknown }>(`/staff/users/${userId}/unban`, {
    method: 'POST',
    body: {},
    auth: 'staff',
  });
}

export function scheduleStaffAppointment(
  applicationId: number,
  payload: {
    branch_id?: number | null;
    scheduled_date: string;
    scheduled_time: string;
    direct_confirm?: boolean;
    message_body?: string;
  }
) {
  return apiFetch<{
    application: StaffApplicationDto;
    appointment: unknown;
    message: ReportMessageDto;
  }>(`/staff/reports/${applicationId}/appointment`, {
    method: 'POST',
    body: payload,
    auth: 'staff',
  });
}
