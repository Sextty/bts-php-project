import { apiDownload, apiFetch } from '@/lib/api/client';

export interface AnalyticsSnapshot {
  meta: { from: string; to: string; branch_id: number | null; status: string | null; scope: 'global' | 'branch'; generated_at: string; currency_note: string };
  kpis: {
    total_applications: number; approved: number; rejected: number; cancelled: number; pending: number;
    approval_rate: number | null; cancellation_rate: number | null; requested_amount_total: number;
    requested_amount_average: number | null; average_processing_hours: number | null;
  };
  timeline: { date: string; created: number; approved: number; rejected: number; cancelled: number }[];
  statuses: { status: string; count: number }[];
  branches: { branch_id: number; name: string; governorate: string; delegation: string | null; application_volume: number; pending_workload: number; approved: number; rejected: number; cancelled: number; average_processing_hours: number | null; appointment_volume: number; appointment_accepted: number; appointment_cancelled: number }[];
  appointments: { total: number; proposed: number; accepted: number; rejected: number; cancelled: number; active_capacity: number; occupied_slots: number; utilization_rate: number | null; average_attempt: number | null; max_attempt: number; next_30_days_active: number };
  workflow: { stages: { stage: string; count: number; completion_rate: number | null }[]; average_hours: { creation_to_submission: number | null; submission_to_first_decision: number | null; decision_to_first_appointment: number | null } };
  credit: { by_currency: { currency: string; applications: number; requested_total: number; requested_average: number | null }[]; financing_composition: Record<'EQP' | 'FDR' | 'AMG' | 'CHP', number>; epr_document_count: number; request_types: { label: string; count: number }[] };
  geography: { governorates: { label: string; count: number }[]; delegations: { label: string; count: number }[]; project_types: { label: string; count: number }[]; activities: { label: string; count: number }[] };
}

export interface AnalyticsDataQuality {
  passed: boolean;
  total_violations: number;
  checks: Record<string, { passed: boolean; violations: number }>;
}

export function getAnalytics(days: number) {
  const to = new Date();
  const from = new Date(to);
  from.setDate(from.getDate() - days + 1);
  const query = new URLSearchParams({ from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) });
  return apiFetch<AnalyticsSnapshot>(`/staff/analytics/overview?${query}`, { auth: 'staff' });
}

export function getAnalyticsDataQuality() {
  return apiFetch<AnalyticsDataQuality>('/staff/analytics/data-quality', { auth: 'staff' });
}

export function downloadAnalyticsCsv(days: number, dataset: 'timeline' | 'statuses' | 'branches' | 'appointments' | 'workflow' | 'credit' | 'geography') {
  const to = new Date();
  const from = new Date(to);
  from.setDate(from.getDate() - days + 1);
  const query = new URLSearchParams({ from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10), dataset, format: 'csv' });
  return apiDownload(`/staff/analytics/export?${query}`);
}
