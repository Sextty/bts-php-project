import { apiFetch } from '@/lib/api/client';

export interface OsqueryTableColumnSchema {
  [columnName: string]: string; // e.g. 'TEXT', 'INTEGER'
}

export interface OsqueryStatusDto {
  native_available: boolean;
  engine_mode: 'native' | 'telemetry_engine';
  version: string;
  tables_count: number;
  tables: Record<string, OsqueryTableColumnSchema>;
  presets_count: number;
}

export interface OsqueryQueryResultDto {
  sql: string;
  columns: string[];
  rows: Record<string, string | number | boolean | null>[];
  count: number;
  execution_time_ms: number;
  engine_mode: 'native' | 'telemetry_engine';
}

export interface OsqueryPresetQueryDto {
  name: string;
  description: string;
  sql: string;
  recommended_interval?: string;
}

export interface OsqueryPresetCategoryDto {
  category: string;
  title: string;
  description: string;
  risk_level: 'info' | 'warning' | 'critical';
  icon: string;
  queries: OsqueryPresetQueryDto[];
}

export interface OsqueryQuickAuditDto {
  system: Record<string, string | number | null> | null;
  os: Record<string, string | number | null> | null;
  open_ports_count: number;
  open_ports: Record<string, string | number | null>[];
  logged_users_count: number;
  logged_users: Record<string, string | number | null>[];
  active_interfaces_count: number;
  active_interfaces: Record<string, string | number | null>[];
  disks: Record<string, string | number | null>[];
  execution_time_ms: number;
  scanned_at: string;
}

/**
 * Fetch Osquery Engine status and supported tables.
 */
export function getOsqueryStatus() {
  return apiFetch<OsqueryStatusDto>('/staff/insights/osquery/status', { auth: 'staff' });
}

/**
 * Execute custom SQL query against Osquery.
 */
export function runOsqueryQuery(sql: string) {
  return apiFetch<OsqueryQueryResultDto>('/staff/insights/osquery/query', {
    method: 'POST',
    body: { sql },
    auth: 'staff',
  });
}

/**
 * Get all curated Osquery query packs.
 */
export function getOsqueryPresets() {
  return apiFetch<OsqueryPresetCategoryDto[]>('/staff/insights/osquery/presets', { auth: 'staff' });
}

/**
 * Run quick security scan.
 */
export function getOsqueryQuickAudit() {
  return apiFetch<OsqueryQuickAuditDto>('/staff/insights/osquery/quick-audit', { auth: 'staff' });
}
