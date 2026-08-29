import { apiFetch } from './client';
import type { TelemetryRow } from './security';

export interface OsqueryStatusData {
  native_available: boolean;
  engine_mode: 'native' | 'telemetry_fallback';
  version: string;
  tables_count: number;
  tables: Record<string, Record<string, string>>;
  presets_count: number;
}

export interface OsqueryQueryResult {
  sql: string;
  columns: string[];
  rows: TelemetryRow[];
  count: number;
  execution_time_ms: number;
  engine_mode: 'native' | 'telemetry_fallback';
}

export interface OsqueryPresetCategory {
  category: string;
  title: string;
  description: string;
  risk_level: 'info' | 'warning' | 'critical';
  icon: string;
  queries: Array<{
    name: string;
    description: string;
    sql: string;
    recommended_interval?: string;
  }>;
}

export interface OsqueryQuickAuditResult {
  system: TelemetryRow;
  os: TelemetryRow;
  open_ports_count: number;
  open_ports: TelemetryRow[];
  logged_users_count: number;
  logged_users: TelemetryRow[];
  active_interfaces_count: number;
  active_interfaces: TelemetryRow[];
  disks: TelemetryRow[];
  execution_time_ms: number;
  scanned_at: string;
}

export async function getSecurityOsqueryStatus(): Promise<OsqueryStatusData> {
  return apiFetch<OsqueryStatusData>('/security/osquery/status');
}

export async function runSecurityOsqueryQuery(sql: string): Promise<OsqueryQueryResult> {
  return apiFetch<OsqueryQueryResult>('/security/osquery/query', {
    method: 'POST',
    body: { sql },
  });
}

export async function getSecurityOsqueryPresets(): Promise<OsqueryPresetCategory[]> {
  return apiFetch<OsqueryPresetCategory[]>('/security/osquery/presets');
}

export async function getSecurityOsqueryQuickAudit(): Promise<OsqueryQuickAuditResult> {
  return apiFetch<OsqueryQuickAuditResult>('/security/osquery/quick-audit');
}
