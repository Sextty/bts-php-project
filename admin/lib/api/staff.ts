import { apiFetch } from '@/lib/api/client';
import type { ApplicationStatus, CreditApplicationDto } from '@/lib/api/credit-applications';

export interface StaffUserDto {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  role: 'staff' | 'admin';
}

export interface StaffApplicationDto extends CreditApplicationDto {
  applicant?: {
    id: number;
    name: string;
    email: string;
    phone: string | null;
  };
}

export function staffLogin(input: { email: string; password: string }) {
  return apiFetch<{ access_token: string; staff_user: StaffUserDto }>('/staff/login', {
    method: 'POST',
    body: input,
  });
}

/**
 * Administrator entrance — the backend only issues tokens here for `role: admin` accounts, and
 * the staff portal (`staffLogin`) only for `role: staff` ones.
 */
export function adminLogin(input: { email: string; password: string }) {
  return apiFetch<{ access_token: string; staff_user: StaffUserDto }>('/staff/admin/login', {
    method: 'POST',
    body: input,
  });
}

export function staffLogout() {
  return apiFetch<null>('/staff/logout', { method: 'POST', auth: 'staff' });
}

export function listStaffApplications(status?: ApplicationStatus) {
  const query = status ? `?status=${encodeURIComponent(status)}` : '';

  return apiFetch<{ applications: StaffApplicationDto[]; meta: { current_page: number; last_page: number; total: number } }>(
    `/staff/applications${query}`,
    { auth: 'staff' }
  );
}

export function getStaffApplication(id: number) {
  return apiFetch<{ application: StaffApplicationDto }>(`/staff/applications/${id}`, { auth: 'staff' });
}

export function approveApplication(id: number) {
  return apiFetch<{ application: StaffApplicationDto }>(`/staff/applications/${id}/approve`, {
    method: 'POST',
    auth: 'staff',
  });
}

export function rejectApplication(id: number, reason: string) {
  return apiFetch<{ application: StaffApplicationDto }>(`/staff/applications/${id}/reject`, {
    method: 'POST',
    body: { reason },
    auth: 'staff',
  });
}

export function adminApproveApplication(id: number) {
  return apiFetch<{ application: StaffApplicationDto }>(`/staff/applications/${id}/admin-approve`, {
    method: 'POST',
    auth: 'staff',
  });
}

export function adminRejectApplication(id: number, reason: string) {
  return apiFetch<{ application: StaffApplicationDto }>(`/staff/applications/${id}/admin-reject`, {
    method: 'POST',
    body: { reason },
    auth: 'staff',
  });
}

export function cancelAdminApplication(id: number) {
  return apiFetch<{ application: StaffApplicationDto }>(`/staff/applications/${id}/admin-cancel`, {
    method: 'POST',
    auth: 'staff',
  });
}
