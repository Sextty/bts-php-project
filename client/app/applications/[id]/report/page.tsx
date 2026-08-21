'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { AlertCircle, XCircle, MessageSquare, Shield } from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { Breadcrumbs, BackLink } from '@/components/breadcrumbs';
import { ReportChat } from '@/components/report-chat';
import {
  getReportMessages,
  sendReportMessageWithAttachment,
  type ReportMessageDto,
  type ReportThreadDto,
} from '@/lib/api/reports';
import { getApplication, type CreditApplicationDto } from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { InlineLoading } from '@/components/page-loading';

export default function ApplicationReportPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [application, setApplication] = useState<CreditApplicationDto | null>(null);
  const [messages, setMessages] = useState<ReportMessageDto[] | null>(null);
  const [isClosed, setIsClosed] = useState(false);
  const [closedReason, setClosedReason] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }
    Promise.all([
      getApplication(applicationId).then(({ application }) => setApplication(application)),
      getReportMessages(applicationId).then((res: ReportThreadDto | { messages: ReportMessageDto[] }) => {
        if ('messages' in res && Array.isArray(res.messages)) {
          setMessages(res.messages);
          setIsClosed(!!(res as ReportThreadDto).is_closed);
          setClosedReason((res as ReportThreadDto).closed_reason ?? null);
        } else if (Array.isArray(res)) {
          setMessages(res);
        }
      }),
    ]).catch((err) => setError(err instanceof ApiError ? err.message : 'Impossible de charger la conversation.'));
  }, [applicationId, router]);

  const applicationNumber = application?.credit_request?.n_demande ?? `Dossier #${applicationId}`;

  return (
    <div className="min-h-screen bg-[#F4F6F8] text-[#1E2D3D]">
      <DashboardHeader />
      <main id="main" className="mx-auto max-w-4xl px-4 sm:px-8 py-8 sm:py-10 space-y-6">
        <BackLink href={`/applications/${applicationId}`} label="Retour aux détails" />
        <Breadcrumbs
          items={[
            { label: 'Tableau de bord', href: '/dashboard' },
            { label: 'Mes demandes', href: '/applications' },
            { label: applicationNumber, href: `/applications/${applicationId}` },
            { label: 'Discussion Conseiller' },
          ]}
          className="mb-2"
        />

        <div className="border-b border-[#E0E4E9] pb-4">
          <p className="overline">Messagerie Sécurisée</p>
          <h1 className="font-display text-3xl font-light text-[#0C1825]">
            Échanges avec votre conseiller BTS
          </h1>
          <p className="text-xs text-[#3D5166] mt-1">
            {application?.status === 'CANCELLED'
              ? 'Votre dossier est annulé. Vous pouvez échanger directement avec l’agence ici.'
              : 'Canal direct de messagerie pour le suivi de votre dossier, la transmission de pièces jointes et la planification de votre rendez-vous.'}
          </p>
        </div>

        {/* Cancelled context */}
        {application?.status === 'CANCELLED' && (
          <div className="p-4 bg-red-50 border border-red-200 rounded-xl text-xs text-red-900 space-y-1">
            <div className="flex items-center gap-2 font-bold text-red-800">
              <XCircle className="size-4 text-red-600" />
              <span>Demande annulée</span>
            </div>
            {application.rejection_reason && (
              <p>Motif : {application.rejection_reason}</p>
            )}
          </div>
        )}

        {/* APPOINTMENT_LOCKED context */}
        {application?.status === 'APPOINTMENT_LOCKED' && (
          <div className="p-4 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900 space-y-1">
            <div className="flex items-center gap-2 font-bold text-amber-800">
              <AlertCircle className="size-4 text-amber-600" />
              <span>Ajustement de créneau nécessaire</span>
            </div>
            <p>
              Votre conseiller BTS prendra contact avec vous via cette messagerie pour convenir d&apos;un nouveau créneau d&apos;entretien.
            </p>
          </div>
        )}

        <ErrorAlert message={error} />

        {!messages && !error && <InlineLoading />}

        {messages && (
          <div className="figma-card p-6 bg-white shadow-xs">
            <ReportChat
              applicationId={applicationId}
              currentSenderType="customer"
              getToken={getToken}
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
