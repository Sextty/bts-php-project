import { apiFetch } from './client';

export type TelemetryValue = string | number | boolean | null;
export type TelemetryRow = Record<string, TelemetryValue>;

export interface SecurityDashboardData {
  metrics: {
    events_today: number;
    total_events: number;
    active_customers: number;
    suspended_customers: number;
    active_staff: number;
    open_ports_count: number;
  };
  system: {
    os_name: string;
    platform: string;
    uptime_days: number;
    uptime_hours: number;
    uptime_seconds: number;
  };
  traffic: {
    days: number;
    total_events: number;
    active_customers: number;
    active_staff: number;
    unique_ips: number | null;
    timeline: Array<{ date: string; events: number }>;
    top_actions: Array<{ action: string; count: number }>;
  };
  recent_events: AuditEventItem[];
}

export interface AuditEventItem {
  id: number;
  action: string;
  created_at: string;
  credit_application_id: number | null;
  actor: {
    type: 'staff' | 'customer' | 'system';
    name: string;
    role: string | null;
    email?: string | null;
  };
  ip_address?: string | null;
  user_agent?: string | null;
  device?: {
    browser: string;
    os: string;
    device: string;
    location?: string;
    ip?: string;
  } | null;
  previous_state?: Record<string, unknown> | null;
  new_state?: Record<string, unknown> | null;
}

export interface PaginatedResponse<T> {
  items: T[];
  meta: {
    current_page: number;
    last_page: number;
    total: number;
    per_page?: number;
  };
}

export interface SecurityUsersData {
  customers: PaginatedResponse<{
    id: number;
    type: 'customer';
    first_name: string;
    last_name: string;
    email: string;
    phone: string | null;
    status: 'active' | 'suspended';
    banned_at: string | null;
    banned_reason: string | null;
    active_tokens_count: number;
    created_at: string;
  }>;
  staff: PaginatedResponse<{
    id: number;
    type: 'staff';
    first_name: string;
    last_name: string;
    email: string;
    role: string;
    status: 'active' | 'suspended';
    branch: { id: number; name: string; ville: string } | null;
    active_tokens_count: number;
    created_at: string;
  }>;
}

export interface SecurityVulnerabilitiesData {
  last_scanned_at: string;
  ports_analyzed_count: number;
  findings: Array<{
    severity: 'critical' | 'warning' | 'info';
    title: string;
    description: string;
    port: number;
    address: string;
    recommendation: string;
  }>;
  summary: {
    critical_count: number;
    warning_count: number;
    info_count: number;
    overall_risk: 'CRITICAL' | 'WARNING' | 'HEALTHY';
  };
}

export interface SecurityTelemetryData {
  system_info: TelemetryRow | null;
  os_version: TelemetryRow | null;
  interfaces: TelemetryRow[];
  listening_ports: TelemetryRow[];
  memory_info: TelemetryRow | null;
  disks: TelemetryRow[];
  uptime: TelemetryRow | null;
  kernel: TelemetryRow | null;
  platform: TelemetryRow | null;
}

export interface SecurityApplicationItem {
  id: number;
  status: string;
  submitted_at: string | null;
  created_at: string | null;
  user: { id: number; name: string; email: string } | null;
  branch: { id: number; name: string; ville: string } | null;
  n_demande: string | null;
  montant: number | string | null;
  validation_steps: Array<{ step: string; status: string }>;
}

export interface SecurityDocumentItem {
  id: number;
  credit_application_id: number;
  document_type: string;
  original_filename: string;
  mime_type: string;
  size_bytes: number;
  ai_verified_at: string | null;
  ai_is_valid: boolean | null;
  ai_confidence: number | null;
  ai_comment: string | null;
  ai_requires_human_review: boolean;
  created_at: string;
  applicant_name: string;
}

export interface SecurityAppointmentItem {
  id: number;
  credit_application_id: number;
  scheduled_date: string | null;
  scheduled_time: string | null;
  status: string;
  attempt_number: number;
  branch: { id: number; name: string; ville: string } | null;
  applicant_name: string;
  created_at: string;
}

export async function getSecurityDashboard(): Promise<SecurityDashboardData> {
  return apiFetch<SecurityDashboardData>('/security/dashboard');
}

export async function getSecurityActivity(params: {
  action?: string;
  search?: string;
  actor_type?: string;
  application_id?: number | string;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
} = {}): Promise<{
  items: AuditEventItem[];
  meta: { current_page: number; last_page: number; total: number; per_page: number };
  available_actions: string[];
}> {
  const q = new URLSearchParams();
  if (params.action) q.set('action', params.action);
  if (params.search) q.set('search', params.search);
  if (params.actor_type) q.set('actor_type', params.actor_type);
  if (params.application_id) q.set('application_id', String(params.application_id));
  if (params.from) q.set('from', params.from);
  if (params.to) q.set('to', params.to);
  if (params.page) q.set('page', String(params.page));
  if (params.per_page) q.set('per_page', String(params.per_page));

  return apiFetch(`/security/activity?${q.toString()}`);
}

export async function getSecurityActivityDetail(id: number): Promise<{ event: AuditEventItem }> {
  return apiFetch<{ event: AuditEventItem }>(`/security/activity/${id}`);
}

export async function getSecurityUsers(params: {
  search?: string;
  status?: string;
  customers_page?: number;
  staff_page?: number;
} = {}): Promise<SecurityUsersData> {
  const q = new URLSearchParams();
  if (params.search) q.set('search', params.search);
  if (params.status) q.set('status', params.status);
  if (params.customers_page) q.set('customers_page', String(params.customers_page));
  if (params.staff_page) q.set('staff_page', String(params.staff_page));

  return apiFetch<SecurityUsersData>(`/security/users?${q.toString()}`);
}

export async function suspendSecurityUser(id: number, reason: string): Promise<{ message: string }> {
  return apiFetch(`/security/users/${id}/suspend`, {
    method: 'POST',
    body: { reason },
  });
}

export async function unsuspendSecurityUser(id: number): Promise<{ message: string }> {
  return apiFetch(`/security/users/${id}/unsuspend`, {
    method: 'POST',
  });
}

export async function revokeSecurityUserTokens(id: number, type: 'customer' | 'staff'): Promise<{ message: string; revoked_count: number }> {
  return apiFetch(`/security/users/${id}/revoke-tokens`, {
    method: 'POST',
    body: { type },
  });
}

export async function securityLogout(): Promise<void> {
  return apiFetch<void>('/staff/logout', { method: 'POST' });
}

export async function getSecurityApplications(params: {
  status?: string;
  search?: string;
  page?: number;
} = {}): Promise<PaginatedResponse<SecurityApplicationItem>> {
  const q = new URLSearchParams();
  if (params.status) q.set('status', params.status);
  if (params.search) q.set('search', params.search);
  if (params.page) q.set('page', String(params.page));

  return apiFetch<PaginatedResponse<SecurityApplicationItem>>(`/security/data/applications?${q.toString()}`);
}

export async function getSecurityDocuments(page: number = 1): Promise<PaginatedResponse<SecurityDocumentItem>> {
  return apiFetch<PaginatedResponse<SecurityDocumentItem>>(`/security/data/documents?page=${page}`);
}

export async function getSecurityAppointments(page: number = 1): Promise<PaginatedResponse<SecurityAppointmentItem>> {
  return apiFetch<PaginatedResponse<SecurityAppointmentItem>>(`/security/data/appointments?page=${page}`);
}

export async function getSecurityTelemetry(): Promise<SecurityTelemetryData> {
  return apiFetch<SecurityTelemetryData>('/security/telemetry');
}

export async function getSecurityVulnerabilities(): Promise<SecurityVulnerabilitiesData> {
  return apiFetch<SecurityVulnerabilitiesData>('/security/vulnerabilities');
}

export async function scanSecurityVulnerabilities(): Promise<SecurityVulnerabilitiesData & { message: string }> {
  return apiFetch<SecurityVulnerabilitiesData & { message: string }>('/security/vulnerabilities/scan', {
    method: 'POST',
  });
}

export async function exportSecurityActivity(params: {
  format: 'json' | 'csv';
  from?: string;
  to?: string;
  action?: string;
  search?: string;
  actor_type?: string;
}): Promise<{ format: string; count: number; exported_at: string; records: AuditEventItem[] }> {
  return apiFetch('/security/export', {
    method: 'POST',
    body: params,
  });
}
