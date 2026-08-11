'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { FilePlus2 } from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { createApplication, listApplications, type CreditApplicationDto } from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';

const STATUS_LABELS: Record<string, string> = {
  DRAFT: 'Draft',
  STEP_1_COMPLETED: 'Client saved',
  STEP_2_COMPLETED: 'Credit request saved',
  STEP_3_COMPLETED: 'Project saved',
  READY_FOR_VALIDATION_1: 'Ready for validation',
  VALIDATION_1_COMPLETED: 'Validation 1 passed',
  VALIDATION_2: 'Validation 2',
  FINAL_LOCKED: 'Finalized',
  SUBMITTED: 'Submitted',
};

/** Where clicking into an in-progress application should resume. */
function resumePath(app: CreditApplicationDto): string {
  if (app.status === 'DRAFT') return `/applications/${app.id}/client`;
  if (app.status === 'STEP_1_COMPLETED') return `/applications/${app.id}/credit`;
  if (app.status === 'STEP_2_COMPLETED') return `/applications/${app.id}/project`;
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
        <div className="mb-6 flex items-center justify-between">
          <h1 className="text-xl font-semibold tracking-tight">Credit applications</h1>
          <Button onClick={handleCreate} disabled={creating} className="gap-1.5">
            <FilePlus2 className="size-4" />
            {creating ? 'Creating…' : 'New application'}
          </Button>
        </div>

        <ErrorAlert message={error} />

        {loading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : applications.length === 0 ? (
          <Card className="border-border/60 border-dashed shadow-none">
            <CardContent className="py-10 text-center text-sm text-muted-foreground">
              You haven&apos;t started a credit application yet.
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-3">
            {applications.map((app) => (
              <Card
                key={app.id}
                className="cursor-pointer border-border/60 shadow-none transition hover:border-primary/40"
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
                  <Badge variant={app.is_locked ? 'default' : 'outline'}>
                    {STATUS_LABELS[app.status] ?? app.status}
                  </Badge>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </main>
    </div>
  );
}
