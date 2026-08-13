'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { ChevronRight, FilePlus2 } from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { createApplication, listApplications, type CreditApplicationDto } from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { InlineLoading } from '@/components/page-loading';
import { StatusBadge } from '@/components/status-badge';
import { STATUS_LABELS } from '@/lib/status-labels';

const APPOINTMENT_STATUSES: CreditApplicationDto['status'][] = [
  'APPOINTMENT_PROPOSED',
  'APPOINTMENT_CONFIRMED',
  'APPOINTMENT_LOCKED',
];

/** Where clicking into an in-progress application should resume. */
function resumePath(app: CreditApplicationDto): string {
  if (app.status === 'DRAFT') return `/applications/${app.id}/client`;
  if (app.status === 'STEP_1_COMPLETED') return `/applications/${app.id}/credit`;
  if (app.status === 'STEP_2_COMPLETED') return `/applications/${app.id}/project`;
  if (APPOINTMENT_STATUSES.includes(app.status)) return `/applications/${app.id}/appointment`;
  return `/applications/${app.id}/validation`;
}

export default function ApplicationsPage() {
  const router = useRouter();
  const [applications, setApplications] = useState<CreditApplicationDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }
    listApplications()
      .then(({ applications }) => setApplications(applications))
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Something went wrong.'))
      .finally(() => setLoading(false));
  }, [router]);

  async function handleCreate() {
    setError(null);
    setCreating(true);
    try {
      const { application } = await createApplication();
      router.push(`/applications/${application.id}/client`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong.');
      setCreating(false);
    }
  }

  return (
    <div className="min-h-screen bg-muted/30">
      <DashboardHeader />
      <main className="mx-auto max-w-2xl px-4 py-10">
        <div className="mb-6 flex items-center justify-between gap-4">
          <div>
            <h1 className="text-title">Credit applications</h1>
            {!loading && (
              <p className="text-sm text-muted-foreground">
                {applications.length === 0 ? 'No applications yet' : `${applications.length} application${applications.length === 1 ? '' : 's'}`}
              </p>
            )}
          </div>
          <Button onClick={handleCreate} disabled={creating} className="gap-1.5 shrink-0">
            <FilePlus2 className="size-4" />
            {creating ? 'Creating…' : 'New application'}
          </Button>
        </div>

        <ErrorAlert message={error} />

        {loading ? (
          <InlineLoading />
        ) : applications.length === 0 ? (
          <Card className="card-surface-empty">
            <CardContent className="flex flex-col items-center gap-2 py-12 text-center text-sm text-muted-foreground">
              <FilePlus2 className="size-8 text-muted-foreground/40" />
              You haven&apos;t started a credit application yet.
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-3">
            {applications.map((app) => (
              <Card
                key={app.id}
                className="card-surface cursor-pointer transition-colors hover:border-primary/40 hover:bg-accent/30"
                onClick={() => router.push(resumePath(app))}
              >
                <CardContent className="flex items-center justify-between py-4">
                  <div>
                    <p className="font-medium">
                      {app.credit_request?.n_demande ?? `Application #${app.id}`}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      Started {new Date(app.created_at).toLocaleDateString()}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <StatusBadge status={app.status} label={STATUS_LABELS[app.status] ?? app.status} />
                    <ChevronRight className="size-4 text-muted-foreground/50" />
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </main>
    </div>
  );
}
