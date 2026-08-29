import { apiFetch } from '@/lib/api/client';

export interface AppointmentClientDto {
  id?: number;
  name: string;
  first_name?: string;
  last_name?: string;
  email?: string;
  phone?: string;
  cin?: string;
  cin_type?: string;
  profession?: string;
}

export interface AppointmentBranchDto {
  id: number;
  name: string;
  address: string;
  phone?: string | null;
  fax?: string | null;
  ville?: string | null;
  latitude?: number | null;
  longitude?: number | null;
  google_maps_url?: string;
}

export interface AppointmentApplicationDto {
  id: number;
  application_number: string;
  status: string;
  amount?: number | null;
  is_report_open?: boolean;
  is_report_closed?: boolean;
}

export interface AppointmentItemDto {
  id: number;
  attempt_number: number;
  max_attempts: number;
  scheduled_date: string;
  scheduled_time: string;
  time_formatted: string;
  status: 'proposed' | 'accepted' | 'rejected' | 'cancelled';
  decided_at?: string | null;
  is_auto_scheduled_future?: boolean;
  created_at?: string;
  application: AppointmentApplicationDto | null;
  client: AppointmentClientDto;
  branch: AppointmentBranchDto | null;
}

export interface AppointmentStatsDto {
  total: number;
  accepted: number;
  proposed: number;
  rejected: number;
  today: number;
  upcoming: number;
}

export interface AppointmentsListResponse {
  appointments: AppointmentItemDto[];
  stats: AppointmentStatsDto;
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface BranchOverviewDto {
  id: number;
  name: string;
  ville?: string | null;
  delegation?: string | null;
  address: string;
  phone?: string | null;
  fax?: string | null;
  opening_hours?: string;
  daily_capacity: number;
  slot_times: string[];
  latitude?: number | null;
  longitude?: number | null;
  google_maps_url?: string;
  is_default: boolean;
  appointments_count: number;
  accepted_appointments_count: number;
  proposed_appointments_count: number;
  today_appointments_count: number;
  upcoming_appointments_count: number;
}

export interface BranchesOverviewResponse {
  branches: BranchOverviewDto[];
  total_branches: number;
}

export interface AppointmentsFilterParams {
  page?: number;
  per_page?: number;
  status?: string;
  branch_id?: number | string;
  date?: string;
  date_from?: string;
  date_to?: string;
  search?: string;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

export function listStaffAppointments(params?: AppointmentsFilterParams) {
  const query = new URLSearchParams();
  if (params) {
    if (params.page) query.set('page', String(params.page));
    if (params.per_page) query.set('per_page', String(params.per_page));
    if (params.status && params.status !== 'all') query.set('status', params.status);
    if (params.branch_id && params.branch_id !== 'all') query.set('branch_id', String(params.branch_id));
    if (params.date) query.set('date', params.date);
    if (params.date_from) query.set('date_from', params.date_from);
    if (params.date_to) query.set('date_to', params.date_to);
    if (params.search) query.set('search', params.search);
    if (params.sort_by) query.set('sort_by', params.sort_by);
    if (params.sort_dir) query.set('sort_dir', params.sort_dir);
  }

  const qs = query.toString();
  return apiFetch<AppointmentsListResponse>(`/staff/appointments${qs ? `?${qs}` : ''}`, {
    auth: 'staff',
  });
}

export function getBranchesOverview() {
  return apiFetch<BranchesOverviewResponse>('/staff/branches/overview', {
    auth: 'staff',
  });
}
