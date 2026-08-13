'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { ChevronRight, MessageCircle } from 'lucide-react';
import { StaffHeader } from '@/components/staff-header';
import { ErrorAlert } from '@/components/error-alert';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { listStaffReports } from '@/lib/api/reports';
import type { StaffApplicationDto } from '@/lib/api/staff';
import { ApiError } from '@/lib/api/client';
import { getStaffToken, getStaffRole } from '@/lib/auth/staff-token';
import { InlineLoading } from '@/components/page-loading';

export default function StaffReportsPage() {
  const router = useRouter();
  const [applications, setApplications] = useState<StaffApplicationDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getStaffToken()) {
      router.replace('/staff/login');
      return;
    }
    listStaffReports()
      .then(({ applications }) => setApplications(applications))
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Something went wrong.'))
      .finally(() => setLoading(false));
  }, [router]);

  return (
    <div className="min-h-screen bg-muted/30">
      <StaffHeader role={getStaffRole() ?? 'staff'} />
      <main className="mx-auto max-w-4xl px-4 py-10">
        <div className="mb-6">
          <h1 className="text-title">Client reports</h1>
          <p className="text-sm text-muted-foreground">
            Applications locked after 3 rejected appointment times — reach out here.
          </p>
        </div>

        <ErrorAlert message={error} />

        {loading ? (
          <InlineLoading />
        ) : applications.length === 0 ? (
          <Card className="card-surface-empty">
            <CardContent className="flex flex-col items-center gap-2 py-12 text-center text-sm text-muted-foreground">
              <MessageCircle className="size-8 text-muted-foreground/40" />
              No locked applications right now.
            </CardContent>
          </Card>
        ) : (
          <Card className="card-surface">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>N° Demande</TableHead>
                  <TableHead>Applicant</TableHead>
                  <TableHead>Started</TableHead>
                  <TableHead className="w-8" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {applications.map((app) => (
                  <TableRow
                    key={app.id}
                    className="cursor-pointer transition-colors hover:bg-accent/40"
                    onClick={() => router.push(`/staff/reports/${app.id}`)}
                  >
                    <TableCell className="font-medium">{app.credit_request?.n_demande ?? `#${app.id}`}</TableCell>
                    <TableCell>{app.applicant?.name ?? '—'}</TableCell>
                    <TableCell>{new Date(app.created_at).toLocaleDateString()}</TableCell>
                    <TableCell>
                      <ChevronRight className="size-4 text-muted-foreground/50" />
                    </TableCell>
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
