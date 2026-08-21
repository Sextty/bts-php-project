'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { StaffHeader } from '@/components/staff-header';
import { ApplicationReviewDetail } from '@/components/application-review-detail';
import { ErrorAlert } from '@/components/error-alert';
import {
  getStaffApplication,
  approveApplication,
  rejectApplication,
  cancelStaffApplication,
  type StaffApplicationDto,
} from '@/lib/api/staff';
import { ApiError } from '@/lib/api/client';
import { getStaffToken, getStaffRole } from '@/lib/auth/staff-token';
import { PageLoading } from '@/components/page-loading';

export default function StaffApplicationDetailPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const role = getStaffRole() ?? 'staff';

  const [application, setApplication] = useState<StaffApplicationDto | null>(null);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    if (!Number.isFinite(applicationId)) {
      router.replace('/dashboard');
      return;
    }
    getStaffApplication(applicationId)
      .then(({ application }) => setApplication(application))
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Impossible de charger ce dossier.'))
      .finally(() => setLoading(false));
  }, [applicationId, router]);

  async function handleApprove() {
    setError(null);
    setWorking(true);
    try {
      const { application } = await approveApplication(applicationId);
      setApplication(application);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur lors de la validation.');
    } finally {
      setWorking(false);
    }
  }

  async function handleReject(reason: string) {
    setError(null);
    setWorking(true);
    try {
      const { application } = await rejectApplication(applicationId, reason);
      setApplication(application);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur lors du refus.');
    } finally {
      setWorking(false);
    }
  }

  async function handleCancel() {
    setError(null);
    setWorking(true);
    try {
      const { application } = await cancelStaffApplication(applicationId);
      setApplication(application);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur lors de l’annulation.');
    } finally {
      setWorking(false);
    }
  }

  if (loading) return <PageLoading />;

  if (!application) {
    return (
      <div className="min-h-screen bg-[#F4F6F8] text-[#1E2D3D]">
        <StaffHeader role={role} />
        <main id="main" className="mx-auto max-w-4xl px-4 sm:px-8 py-10 space-y-4">
          <Link
            href="/dashboard"
            className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#3D5166] hover:text-[#0C1825]"
          >
            <ArrowLeft className="size-3.5" />
            <span>Retour à la file d&apos;attente</span>
          </Link>
          <ErrorAlert message={error ?? 'Dossier introuvable.'} />
        </main>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-[#F4F6F8] text-[#1E2D3D]">
      <StaffHeader role={role} />
      <main id="main" className="mx-auto max-w-5xl px-4 sm:px-8 py-8 sm:py-10 space-y-6">
        <Link
          href="/dashboard"
          className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#3D5166] hover:text-[#0C1825]"
        >
          <ArrowLeft className="size-3.5" />
          <span>Retour à la file des dossiers</span>
        </Link>

        <ApplicationReviewDetail
          application={application}
          canDecide={application.status === 'SUBMITTED'}
          onApprove={handleApprove}
          onReject={handleReject}
          onCancel={
            application.status === 'SUBMITTED' || application.status === 'STAFF_APPROVED'
              ? handleCancel
              : undefined
          }
          working={working}
          error={error}
        />
      </main>
    </div>
  );
}
