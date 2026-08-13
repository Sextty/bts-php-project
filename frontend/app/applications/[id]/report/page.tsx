'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { ReportChat } from '@/components/report-chat';
import { getReportMessages, sendReportMessage, type ReportMessageDto } from '@/lib/api/reports';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { InlineLoading } from '@/components/page-loading';

export default function ApplicationReportPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [messages, setMessages] = useState<ReportMessageDto[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }
    getReportMessages(applicationId)
      .then(({ messages }) => setMessages(messages))
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Something went wrong.'));
  }, [applicationId, router]);

  return (
    <div className="min-h-screen bg-muted/30">
      <DashboardHeader />
      <main className="mx-auto max-w-2xl px-4 py-10">
        <div className="mb-6">
          <h1 className="text-title">Talk to BTS Bank</h1>
          <p className="text-sm text-muted-foreground">
            A staff member will reply here to help arrange your appointment.
          </p>
        </div>

        <ErrorAlert message={error} />

        {!messages && !error && <InlineLoading />}

        {messages && (
          <ReportChat
            applicationId={applicationId}
            currentSenderType="customer"
            getToken={getToken}
            initialMessages={messages}
            onSend={async (body) => {
              const { message } = await sendReportMessage(applicationId, body);
              return message;
            }}
          />
        )}
      </main>
    </div>
  );
}
