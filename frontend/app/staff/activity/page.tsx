'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Activity, ShieldCheck } from 'lucide-react';
import { StaffHeader } from '@/components/staff-header';
import { ErrorAlert } from '@/components/error-alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { TrendChart, BarList } from '@/components/charts/trend-chart';
import { InlineLoading, PageLoading } from '@/components/page-loading';
import {
  getActivityLogs,
  getTraffic,
  type ActivityLogDto,
  type TrafficDto,
} from '@/lib/api/staff-insights';
import { ApiError } from '@/lib/api/client';
import { getStaffRole, getStaffToken } from '@/lib/auth/staff-token';

function shortDate(iso: string): string {
  const d = new Date(iso);
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
}

/** "credit_application.staff_approved" → "credit application · staff approved" */
function humanAction(action: string): string {
  return action.split('.').join(' · ').replace(/_/g, ' ');
}

export default function ActivityPage() {
  const router = useRouter();
  const role = getStaffRole() ?? 'staff';

  const [traffic, setTraffic] = useState<TrafficDto | null>(null);
  const [logs, setLogs] = useState<ActivityLogDto[]>([]);
  const [actions, setActions] = useState<string[]>([]);
  const [actionFilter, setActionFilter] = useState<string>('all');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [logsLoading, setLogsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const loadLogs = useCallback(async (nextPage: number, action: string) => {
    setLogsLoading(true);
    try {
      const data = await getActivityLogs({
        page: nextPage,
        action: action === 'all' ? undefined : action,
      });
      setLogs(data.logs);
      setActions(data.available_actions);
      setLastPage(data.meta.last_page);
      setTotal(data.meta.total);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setLogsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!getStaffToken()) {
      router.replace('/staff/login');
      return;
    }
    Promise.all([getTraffic(14), loadLogs(1, 'all')])
      .then(([t]) => setTraffic(t))
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Something went wrong.'))
      .finally(() => setLoading(false));
  }, [router, loadLogs]);

  function changeAction(value: string) {
    setActionFilter(value);
    setPage(1);
    loadLogs(1, value);
  }

  function goToPage(next: number) {
    setPage(next);
    loadLogs(next, actionFilter);
  }

  if (loading) return <PageLoading />;

  const isAdmin = role === 'admin';

  return (
    <div className="min-h-screen bg-muted/30">
      <StaffHeader role={role} />
      <main className="mx-auto max-w-5xl px-4 py-10">
        <div className="mb-6">
          <h1 className="text-title">Logs &amp; traffic</h1>
          <p className="text-sm text-muted-foreground">
            {isAdmin
              ? 'Full audit trail, including authentication events and network details.'
              : 'Application activity. Authentication events and personal network details are admin-only.'}
          </p>
        </div>

        <ErrorAlert message={error} />

        {!isAdmin && (
          <div className="mb-6 flex items-start gap-2 rounded-lg border border-border/60 bg-card px-3 py-2.5 text-sm text-muted-foreground">
            <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary" />
            <span>
              You&apos;re seeing the staff view. Customer IP addresses, devices and login/OTP events are
              restricted to administrators.
            </span>
          </div>
        )}

        {traffic && (
          <div className="space-y-6">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Stat label={`Events (${traffic.days}d)`} value={traffic.total_events} />
              <Stat label="Active customers" value={traffic.active_customers} />
              <Stat label="Active staff" value={traffic.active_staff} />
              <Stat label="Unique IPs" value={traffic.unique_ips === null ? 'Admin only' : traffic.unique_ips} />
            </div>

            <Card className="card-surface">
              <CardHeader>
                <CardTitle>Activity, last {traffic.days} days</CardTitle>
              </CardHeader>
              <CardContent>
                <TrendChart
                  height={190}
                  labels={traffic.timeline.map((t) => shortDate(t.date))}
                  series={[
                    {
                      key: 'events',
                      label: 'Events',
                      colorVar: '--viz-series-1',
                      values: traffic.timeline.map((t) => t.events),
                    },
                  ]}
                />
              </CardContent>
            </Card>

            <Card className="card-surface">
              <CardHeader>
                <CardTitle>Most frequent actions</CardTitle>
              </CardHeader>
              <CardContent>
                <BarList
                  items={traffic.top_actions.map((a) => ({
                    label: humanAction(a.action),
                    value: a.count,
                    hint: a.action,
                  }))}
                  emptyLabel="No activity recorded yet."
                />
              </CardContent>
            </Card>
          </div>
        )}

        <Card className="card-surface mt-6">
          <CardHeader>
            <CardTitle>Audit log</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {/* Filters sit in one row above the table. */}
            <div className="flex flex-wrap items-end gap-3">
              <div className="space-y-2">
                <Label htmlFor="action-filter">Action</Label>
                <Select value={actionFilter} onValueChange={(v) => changeAction(v ?? 'all')}>
                  <SelectTrigger id="action-filter" className="min-w-56">
                    <SelectValue placeholder="All actions" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All actions</SelectItem>
                    {actions.map((a) => (
                      <SelectItem key={a} value={a}>
                        {humanAction(a)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <p className="pb-2 text-sm text-muted-foreground">
                {total} event{total === 1 ? '' : 's'}
              </p>
            </div>

            {logsLoading ? (
              <InlineLoading />
            ) : logs.length === 0 ? (
              <div className="flex flex-col items-center gap-2 py-10 text-center text-sm text-muted-foreground">
                <Activity className="size-8 text-muted-foreground/40" />
                No events match this filter.
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>When</TableHead>
                        <TableHead>Actor</TableHead>
                        <TableHead>Action</TableHead>
                        <TableHead>File</TableHead>
                        {isAdmin && <TableHead>IP</TableHead>}
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {logs.map((log) => (
                        <TableRow key={log.id}>
                          <TableCell className="whitespace-nowrap text-muted-foreground">
                            {new Date(log.created_at).toLocaleString()}
                          </TableCell>
                          <TableCell>
                            <span className="font-medium">{log.actor.name}</span>
                            {/* A system entry's name already says "System" — a badge repeating it
                                would read as "SystemSystem". */}
                            {log.actor.type !== 'system' && (
                              <Badge variant="outline" className="ml-1.5 capitalize">
                                {log.actor.role ?? log.actor.type}
                              </Badge>
                            )}
                          </TableCell>
                          <TableCell className="whitespace-nowrap">{humanAction(log.action)}</TableCell>
                          <TableCell className="text-muted-foreground">
                            {log.credit_application_id ? `#${log.credit_application_id}` : '—'}
                          </TableCell>
                          {isAdmin && (
                            <TableCell className="text-muted-foreground tabular-nums">
                              {log.ip_address ?? '—'}
                            </TableCell>
                          )}
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>

                {lastPage > 1 && (
                  <div className="flex items-center justify-between pt-1">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={page <= 1 || logsLoading}
                      onClick={() => goToPage(page - 1)}
                    >
                      Previous
                    </Button>
                    <span className="text-sm text-muted-foreground">
                      Page {page} of {lastPage}
                    </span>
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={page >= lastPage || logsLoading}
                      onClick={() => goToPage(page + 1)}
                    >
                      Next
                    </Button>
                  </div>
                )}
              </>
            )}
          </CardContent>
        </Card>
      </main>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number | string }) {
  return (
    <Card className="card-surface">
      <CardContent className="space-y-0.5 py-4">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className="text-2xl font-semibold">{value}</p>
      </CardContent>
    </Card>
  );
}
