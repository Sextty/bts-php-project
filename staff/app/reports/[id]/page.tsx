'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { StaffHeader } from '@/components/staff-header';
import { ErrorAlert } from '@/components/error-alert';
import { ReportChat } from '@/components/report-chat';
import {
  getStaffReportMessages,
  sendReportMessageWithAttachment,
  type ReportMessageDto,
} from '@/lib/api/reports';
import { ApiError } from '@/lib/api/client';
import { getStaffToken, getStaffRole } from '@/lib/auth/staff-token';
import { InlineLoading } from '@/components/page-loading';

export default function StaffReportDetailPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const role = getStaffRole() ?? 'staff';

  const [messages, setMessages] = useState<ReportMessageDto[] | null>(null);
  const [isClosed, setIsClosed] = useState(false);
  const [closedReason, setClosedReason] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    if (!Number.isFinite(applicationId)) {
      router.replace('/reports');
      return;
    }
    getStaffReportMessages(applicationId)
      .then((res: { messages: ReportMessageDto[]; is_closed?: boolean; closed_reason?: string | null }) => {
        setMessages(res.messages || []);
        setIsClosed(!!res.is_closed);
        setClosedReason(res.closed_reason ?? null);
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Impossible de charger la conversation.'));
  }, [applicationId, router]);

  return (
    <div className="staff-page text-[#1E2D3D]">
      <StaffHeader role={role} />
      <main id="main" className="staff-main max-w-5xl space-y-6">
        <Link
          href="/reports"
          className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#3D5166] hover:text-[#0C1825]"
        >
          <ArrowLeft className="size-3.5" />
          <span>Retour aux signalements</span>
        </Link>

        <div className="staff-page-hero">
          <p className="overline">Discussion Sécurisée</p>
          <h1 className="font-display text-2xl font-light text-[#0C1825]">
            Échanges pour le Dossier #{applicationId}
          </h1>
          <p className="text-xs text-[#3D5166] mt-1">
            Canal de communication direct avec le demandeur de crédit, partage de pièces jointes et gestion des rendez-vous.
          </p>
        </div>

        <ErrorAlert message={error} />

        {!messages && !error && (
          <div className="figma-card p-12 bg-white text-center">
            <InlineLoading />
          </div>
        )}

        {messages && (
          <div className="figma-card p-6 bg-white shadow-xs">
            <ReportChat
              applicationId={applicationId}
              currentSenderType="staff"
              getToken={getStaffToken}
              initialMessages={messages}
              isClosed={isClosed}
              closedReason={closedReason}
              onSend={async (body, file) => {
                const { message } = await sendReportMessageWithAttachment(applicationId, body, file);
                return message;
              }}
            />
          </div>
        )}
      </main>
    </div>
  );
}
