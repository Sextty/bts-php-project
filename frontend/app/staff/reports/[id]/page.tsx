'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { StaffHeader } from '@/components/staff-header';
import { ErrorAlert } from '@/components/error-alert';
import { ReportChat } from '@/components/report-chat';
import { getStaffReportMessages, sendStaffReportMessage, type ReportMessageDto } from '@/lib/api/reports';
import { ApiError } from '@/lib/api/client';
import { getStaffToken, getStaffRole } from '@/lib/auth/staff-token';
import { InlineLoading } from '@/components/page-loading';

export default function StaffReportDetailPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [messages, setMessages] = useState<ReportMessageDto[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getStaffToken()) {
      router.replace('/staff/login');
      return;
    }
    getStaffReportMessages(applicationId)
      .then(({ messages }) => setMessages(messages))
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Something went wrong.'));
  }, [applicationId, router]);

  return (
    <div className="min-h-screen bg-muted/30">
      <StaffHeader role={getStaffRole() ?? 'staff'} />
      <main className="mx-auto max-w-2xl px-4 py-10">
        <div className="mb-6">
          <h1 className="text-title">Application #{applicationId}</h1>
          <p className="text-sm text-muted-foreground">Locked after 3 rejected appointment times.</p>
        </div>

        <ErrorAlert message={error} />

        {!messages && !error && <InlineLoading />}

        {messages && (
          <ReportChat
            applicationId={applicationId}
            currentSenderType="staff"
            getToken={getStaffToken}
            initialMessages={messages}
            onSend={async (body) => {
              const { message } = await sendStaffReportMessage(applicationId, body);
              return message;
            }}
          />
        )}
      </main>
    </div>
  );
}
