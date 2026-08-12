'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { StaffHeader } from '@/components/staff-header';
import { ErrorAlert } from '@/components/error-alert';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { listStaffApplications, type StaffApplicationDto } from '@/lib/api/staff';
import { ApiError } from '@/lib/api/client';
import { getStaffToken } from '@/lib/auth/staff-token';

export default function AdminDashboardPage() {
  const router = useRouter();
  const [applications, setApplications] = useState<StaffApplicationDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getStaffToken()) {
      router.replace('/staff/login');
      return;
    }
    listStaffApplications('STAFF_APPROVED')
      .then(({ applications }) => setApplications(applications))
      .catch((err) => {
        // A staff-role token hitting the (server-enforced) admin-only list would 403 — send them
        // back to their own queue rather than stranding them on an error page.
        if (err instanceof ApiError && err.status === 403) {
          router.replace('/staff/dashboard');
          return;
        }
        setError(err instanceof ApiError ? err.message : 'Something went wrong.');
      })
      .finally(() => setLoading(false));
  }, [router]);

  return (
    <div className="min-h-screen bg-muted/30">
      <StaffHeader role="admin" />
      <main className="mx-auto max-w-4xl px-4 py-10">
        <h1 className="mb-6 text-xl font-semibold tracking-tight">Applications awaiting final decision</h1>

        <ErrorAlert message={error} />

        {loading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : applications.length === 0 ? (
          <Card className="border-border/60 border-dashed shadow-none">
            <CardContent className="py-10 text-center text-sm text-muted-foreground">
              Nothing waiting for a final decision right now.
            </CardContent>
          </Card>
        ) : (
          <Card className="border-border/60 shadow-sm">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>N° Demande</TableHead>
                  <TableHead>Applicant</TableHead>
                  <TableHead>Submitted</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {applications.map((app) => (
                  <TableRow
                    key={app.id}
                    className="cursor-pointer"
                    onClick={() => router.push(`/staff/admin/${app.id}`)}
                  >
                    <TableCell className="font-medium">{app.credit_request?.n_demande ?? `#${app.id}`}</TableCell>
                    <TableCell>{app.applicant?.name ?? '—'}</TableCell>
                    <TableCell>{app.submitted_at ? new Date(app.submitted_at).toLocaleDateString() : '—'}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        )}
      </main>
    </div>
  );
}
