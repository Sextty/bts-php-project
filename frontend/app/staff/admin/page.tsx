'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { ChevronRight, Inbox } from 'lucide-react';
import { StaffHeader } from '@/components/staff-header';
import { ErrorAlert } from '@/components/error-alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { TrendChart, BarList } from '@/components/charts/trend-chart';
import { InlineLoading, PageLoading } from '@/components/page-loading';
import { listStaffApplications, type StaffApplicationDto } from '@/lib/api/staff';
import { getDashboard, type DashboardDto } from '@/lib/api/staff-insights';
import { ApiError } from '@/lib/api/client';
import { getStaffToken } from '@/lib/auth/staff-token';
import { statusLabel } from '@/lib/status-labels';

/** Short weekday+day for the x-axis, e.g. "12/08". */
function shortDate(iso: string): string {
  const d = new Date(iso);
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
}

export default function AdminDashboardPage() {
  const router = useRouter();
  const [dashboard, setDashboard] = useState<DashboardDto | null>(null);
  const [queue, setQueue] = useState<StaffApplicationDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [queueLoading, setQueueLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getStaffToken()) {
      router.replace('/staff/login');
      return;
    }

    getDashboard(30)
      .then(setDashboard)
      .catch((err) => {
        // A staff-role token hitting the admin-only dashboard gets 403 from the server — send
        // them to their own queue rather than stranding them on an error page.
        if (err instanceof ApiError && err.status === 403) {
          router.replace('/staff/dashboard');
          return;
        }
        setError(err instanceof ApiError ? err.message : 'Something went wrong.');
      })
      .finally(() => setLoading(false));

    listStaffApplications('STAFF_APPROVED')
      .then(({ applications }) => setQueue(applications))
      .catch(() => {
        /* The queue is secondary here; the dashboard fetch above surfaces auth problems. */
      })
      .finally(() => setQueueLoading(false));
  }, [router]);

  if (loading) return <PageLoading />;

  return (
    <div className="min-h-screen bg-muted/30">
      <StaffHeader role="admin" />
      <main className="mx-auto max-w-5xl px-4 py-10">
        <div className="mb-6">
          <h1 className="text-title">Overview</h1>
          <p className="text-sm text-muted-foreground">Portfolio health across the whole caseload.</p>
        </div>

        <ErrorAlert message={error} />

        {dashboard && (
          <div className="space-y-6">
            {/* KPI row — headline numbers belong in stat tiles, not a chart. */}
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Stat label="Total applications" value={dashboard.kpis.total} />
              <Stat label="In progress" value={dashboard.kpis.in_progress} hint="Customer still filling the form" />
              <Stat label="Awaiting staff" value={dashboard.kpis.awaiting_staff} emphasis={dashboard.kpis.awaiting_staff > 0} />
              <Stat label="Awaiting your decision" value={dashboard.kpis.awaiting_admin} emphasis={dashboard.kpis.awaiting_admin > 0} />
              <Stat label="Approved" value={dashboard.kpis.approved} />
              <Stat label="Rejected" value={dashboard.kpis.rejected} />
              <Stat
                label="Approval rate"
                value={dashboard.kpis.approval_rate === null ? '—' : `${dashboard.kpis.approval_rate}%`}
                hint="Of decided files"
              />
              <Stat
                label="Avg. decision time"
                value={dashboard.kpis.avg_decision_hours === null ? '—' : `${dashboard.kpis.avg_decision_hours}h`}
                hint="Submission → decision"
              />
            </div>

            <Card className="card-surface">
              <CardHeader>
                <CardTitle>Last 30 days</CardTitle>
              </CardHeader>
              <CardContent>
                <TrendChart
                  labels={dashboard.timeline.map((t) => shortDate(t.date))}
                  series={[
                    { key: 'created', label: 'Created', colorVar: '--viz-series-1', values: dashboard.timeline.map((t) => t.created) },
                    { key: 'approved', label: 'Approved', colorVar: '--viz-series-3', values: dashboard.timeline.map((t) => t.approved) },
                    { key: 'rejected', label: 'Rejected', colorVar: '--viz-series-2', values: dashboard.timeline.map((t) => t.rejected) },
                  ]}
                />
              </CardContent>
            </Card>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <Card className="card-surface">
                <CardHeader>
                  <CardTitle>Pipeline</CardTitle>
                </CardHeader>
                <CardContent>
                  <BarList
                    items={dashboard.pipeline.map((p) => ({ label: statusLabel(p.status), value: p.count, hint: p.status }))}
                    emptyLabel="No applications yet."
                  />
                </CardContent>
              </Card>

              <Card className="card-surface">
                <CardHeader>
                  <CardTitle>Team activity</CardTitle>
                </CardHeader>
                <CardContent>
                  {dashboard.team.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">No decisions recorded yet.</p>
                  ) : (
                    <ul className="space-y-3">
                      {dashboard.team.map((m) => (
                        <li key={m.staff_user_id} className="flex items-center justify-between gap-3">
                          <div className="min-w-0">
                            <p className="truncate text-sm font-medium">{m.name}</p>
                            {m.role && (
                              <Badge variant="outline" className="mt-0.5 capitalize">
                                {m.role}
                              </Badge>
                            )}
                          </div>
                          <div className="flex shrink-0 gap-4 text-sm tabular-nums">
                            <span className="text-muted-foreground">
                              approved <span className="font-medium text-foreground">{m.approvals}</span>
                            </span>
                            <span className="text-muted-foreground">
                              rejected <span className="font-medium text-foreground">{m.rejections}</span>
                            </span>
                          </div>
                        </li>
                      ))}
                    </ul>
                  )}
                </CardContent>
              </Card>
            </div>

            <Card className="card-surface">
              <CardHeader>
                <CardTitle>Awaiting final decision</CardTitle>
              </CardHeader>
              <CardContent className="px-0">
                {queueLoading ? (
                  <InlineLoading />
                ) : queue.length === 0 ? (
                  <div className="flex flex-col items-center gap-2 py-10 text-center text-sm text-muted-foreground">
                    <Inbox className="size-8 text-muted-foreground/40" />
                    Nothing waiting for a final decision right now.
                  </div>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>N° Demande</TableHead>
                        <TableHead>Applicant</TableHead>
                        <TableHead>Submitted</TableHead>
                        <TableHead className="w-8" />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {queue.map((app) => (
                        <TableRow
                          key={app.id}
                          className="cursor-pointer transition-colors hover:bg-accent/40"
                          onClick={() => router.push(`/staff/admin/${app.id}`)}
                        >
                          <TableCell className="font-medium">{app.credit_request?.n_demande ?? `#${app.id}`}</TableCell>
                          <TableCell>{app.applicant?.name ?? '—'}</TableCell>
                          <TableCell>{app.submitted_at ? new Date(app.submitted_at).toLocaleDateString() : '—'}</TableCell>
                          <TableCell>
                            <ChevronRight className="size-4 text-muted-foreground/50" />
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                )}
              </CardContent>
            </Card>
          </div>
        )}
      </main>
    </div>
  );
}

function Stat({
  label,
  value,
  hint,
  emphasis = false,
}: {
  label: string;
  value: number | string;
  hint?: string;
  emphasis?: boolean;
}) {
  return (
    <Card className="card-surface">
      <CardContent className="space-y-0.5 py-4">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className={`text-2xl font-semibold ${emphasis ? 'text-primary' : ''}`}>{value}</p>
        {hint && <p className="text-[0.7rem] text-muted-foreground">{hint}</p>}
      </CardContent>
    </Card>
  );
}
