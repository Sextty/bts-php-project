'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { StaffHeader } from '@/components/staff-header';
import { ApplicationReviewDetail } from '@/components/application-review-detail';
import {
  getStaffApplication,
  adminApproveApplication,
  adminRejectApplication,
  type StaffApplicationDto,
} from '@/lib/api/staff';
import { ApiError } from '@/lib/api/client';
import { getStaffToken } from '@/lib/auth/staff-token';

export default function AdminApplicationDetailPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [application, setApplication] = useState<StaffApplicationDto | null>(null);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getStaffToken()) {
      router.replace('/staff/login');
      return;
    }
    getStaffApplication(applicationId)
      .then(({ application }) => setApplication(application))
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Something went wrong.'))
      .finally(() => setLoading(false));
  }, [applicationId, router]);

  async function handleApprove() {
    setError(null);
    setWorking(true);
    try {
      const { application } = await adminApproveApplication(applicationId);
      setApplication(application);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setWorking(false);
    }
  }

  async function handleReject(reason: string) {
    setError(null);
    setWorking(true);
    try {
      const { application } = await adminRejectApplication(applicationId, reason);
      setApplication(application);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setWorking(false);
    }
  }

  if (loading) {
    return <div className="flex min-h-screen items-center justify-center text-muted-foreground">Loading…</div>;
  }
  if (!application) return null;

  return (
    <div className="min-h-screen bg-muted/30">
      <StaffHeader role="admin" />
      <main className="mx-auto max-w-2xl px-4 py-10">
        <Link href="/staff/admin" className="mb-6 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" />
          Back to queue
        </Link>
        <ApplicationReviewDetail
          application={application}
          canDecide={application.status === 'STAFF_APPROVED'}
          onApprove={handleApprove}
          onReject={handleReject}
          working={working}
          error={error}
        />
      </main>
    </div>
  );
}
