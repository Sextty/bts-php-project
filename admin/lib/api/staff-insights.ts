import { apiFetch } from '@/lib/api/client';

export interface DashboardKpis {
  total: number;
  in_progress: number;
  awaiting_staff: number;
  awaiting_admin: number;
  approved: number;
  rejected: number;
  /** Null until at least one application has been decided. */
  approval_rate: number | null;
  avg_decision_hours: number | null;
}

export interface PipelineEntry {
  status: string;
  count: number;
}

export interface TimelinePoint {
  date: string;
  created: number;
  approved: number;
  rejected: number;
}

export interface TeamMemberStats {
  staff_user_id: number;
  name: string;
  role: 'staff' | 'admin' | null;
  approvals: number;
  rejections: number;
}

export interface DashboardDto {
  kpis: DashboardKpis;
  pipeline: PipelineEntry[];
  timeline: TimelinePoint[];
  team: TeamMemberStats[];
}

export interface ActivityActor {
  type: 'staff' | 'customer' | 'system';
  name: string;
  role: 'staff' | 'admin' | null;
}

export interface NetworkAdapterDto {
  name: string;
  raw_name: string;
  description: string;
  mac_address: string;
  ipv4?: string | null;
  ipv6?: string | null;
  subnet_mask?: string | null;
  gateway?: string | null;
  dns?: string | null;
  status: string;
  is_active: boolean;
  is_primary_internet?: boolean;
  speed?: string | null;
  type: 'wifi' | 'ethernet' | 'vmware' | 'hyperv' | 'bluetooth' | 'virtual' | string;
}

export interface DeviceDetailsDto {
  os: string;
  os_short?: string;
  os_version?: string | null;
  os_family: 'windows' | 'android' | 'ios' | 'macos' | 'linux' | 'chromeos' | 'unknown' | string;
  os_build?: string | null;
  architecture?: string;
  computer_model?: string | null;
  cpu?: string | null;
  ram?: string | null;
  gpu?: string | null;
  browser: string;
  browser_version?: string | null;
  device_type: 'desktop' | 'mobile' | 'tablet' | 'bot' | 'unknown';
  device_model: string;
  device_name: string;
  network_adapters?: NetworkAdapterDto[];
  active_network_adapter?: string | null;
  location: string;
  country: string;
  country_code: string;
  city: string;
  mac_address: string;
  device_fingerprint: string;
  ip_address?: string | null;
}

/**
 * Fields beyond `actor`/`action` are admin-only — the API omits them entirely for staff, so they
 * are optional here rather than nullable. Never render them without checking presence.
 */
export interface ActivityLogDto {
  id: number;
  action: string;
  created_at: string;
  credit_application_id: number | null;
  actor: ActivityActor;
  ip_address?: string | null;
  user_agent?: string | null;
  device?: DeviceDetailsDto | null;
  previous_state?: Record<string, unknown> | null;
  new_state?: Record<string, unknown> | null;
}

export interface TrafficDto {
  days: number;
  total_events: number;
  active_customers: number;
  active_staff: number;
  /** Null for non-admin viewers. */
  unique_ips: number | null;
  timeline: { date: string; events: number }[];
  top_actions: { action: string; count: number }[];
}

/** Admin-only; a staff token gets 403 from the server. */
export function getDashboard(days = 30) {
  return apiFetch<DashboardDto>(`/staff/dashboard?days=${days}`, { auth: 'staff' });
}

export function getActivityLogs(params: { action?: string; page?: number; per_page?: number } = {}) {
  const query = new URLSearchParams();
  if (params.action) query.set('action', params.action);
  if (params.page) query.set('page', String(params.page));
  query.set('per_page', String(params.per_page ?? 30));

  return apiFetch<{
    logs: ActivityLogDto[];
    meta: { current_page: number; last_page: number; total: number; per_page: number };
    available_actions: string[];
  }>(`/staff/activity?${query.toString()}`, { auth: 'staff' });
}

export function getTraffic(days = 14) {
  return apiFetch<TrafficDto>(`/staff/activity/traffic?days=${days}`, { auth: 'staff' });
}
