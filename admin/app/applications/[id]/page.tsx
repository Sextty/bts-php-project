'use client';

import { useCallback, useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { ApplicationReviewDetail } from '@/components/application-review-detail';
import { ErrorAlert } from '@/components/error-alert';
import {
  getStaffApplication,
  adminApproveApplication,
  adminRejectApplication,
  cancelAdminApplication,
  type StaffApplicationDto,
} from '@/lib/api/staff';
import { ApiError } from '@/lib/api/client';
import { getStaffToken } from '@/lib/auth/staff-token';
import { PageLoading } from '@/components/page-loading';

export default function AdminApplicationDetailPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [application, setApplication] = useState<StaffApplicationDto | null>(null);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchApplication = useCallback(async (showLoading = false) => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    if (!Number.isFinite(applicationId)) {
      router.replace('/applications');
      return;
    }
    if (showLoading) setLoading(true);
    try {
      const result = await getStaffApplication(applicationId);
      setApplication(result.application);
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur de chargement.');
    } finally {
      setLoading(false);
    }
  }, [applicationId, router]);

  useEffect(() => {
    queueMicrotask(() => void fetchApplication(true));
    const refresh = () => {
      if (document.visibilityState === 'visible') void fetchApplication();
    };
    const interval = window.setInterval(refresh, 10_000);
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', refresh);
    return () => {
      window.clearInterval(interval);
      window.removeEventListener('focus', refresh);
      document.removeEventListener('visibilitychange', refresh);
    };
  }, [fetchApplication]);

  async function handleApprove() {
    setError(null);
    setWorking(true);
    try {
      const { application } = await adminApproveApplication(applicationId);
      setApplication(application);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Erreur lors de l'approbation.");
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
      setError(err instanceof ApiError ? err.message : 'Erreur lors du rejet.');
    } finally {
      setWorking(false);
    }
  }

  async function handleCancel() {
    setError(null);
    setWorking(true);
    try {
      const { application } = await cancelAdminApplication(applicationId);
      setApplication(application);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Erreur lors de l'annulation.");
    } finally {
      setWorking(false);
    }
  }

  if (loading) {
    return <PageLoading />;
  }

  if (!application) {
    return (
      <div className="admin-page max-w-6xl">
        <Link href="/applications" className="mb-6 inline-flex items-center gap-1.5 text-sm text-[#3D5166] hover:text-[#C0272D] transition-colors">
          <ArrowLeft className="size-4" />
          Retour aux dossiers
        </Link>
        <ErrorAlert message={error} />
      </div>
    );
  }

  return (
    <div className="admin-page max-w-6xl">
      <Link href="/applications" className="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-[#3D5166] hover:text-[#C0272D] transition-colors">
        <ArrowLeft className="size-4" />
        Retour aux dossiers
      </Link>
      <ApplicationReviewDetail
        application={application}
        canDecide={application.status === 'STAFF_APPROVED'}
        onApprove={handleApprove}
        onReject={handleReject}
        onCancel={application.status === 'STAFF_APPROVED' ? handleCancel : undefined}
        working={working}
        error={error}
      />
    </div>
  );
}
