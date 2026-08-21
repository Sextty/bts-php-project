'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  MessageSquareText,
  Inbox,
  ChevronRight,
  Clock,
  RefreshCw,
} from 'lucide-react';
import { ErrorAlert } from '@/components/error-alert';
import { PageLoading } from '@/components/page-loading';
import { getReports, type ReportThreadDto } from '@/lib/api/reports';
import type { StaffApplicationDto } from '@/lib/api/staff';
import { ApiError } from '@/lib/api/client';
import { getStaffToken } from '@/lib/auth/staff-token';
import { statusLabel, statusColor } from '@/lib/status-labels';

export default function ReportsListPage() {
  const router = useRouter();
  const [reports, setReports] = useState<ReportThreadDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  async function fetchReports(isRefresh = false) {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    if (isRefresh) setRefreshing(true);
    try {
      const data = await getReports();
      if (Array.isArray(data)) {
        setReports(data);
      } else if (Array.isArray(data?.reports)) {
        setReports(data.reports);
      } else if (Array.isArray(data?.applications)) {
        // Transform StaffApplicationDto to ReportThreadDto
        const items: ReportThreadDto[] = data.applications.map((app: StaffApplicationDto) => ({
          id: app.id,
          credit_application_id: app.id,
          applicant_name: app.applicant?.name ?? `Dossier #${app.id}`,
          status: app.status,
          messages_count: (app as unknown as { report_messages_count?: number }).report_messages_count ?? 0,
          last_message_at: app.submitted_at ?? app.created_at ?? null,
          created_at: app.created_at,
        }));
        setReports(items);
      } else {
        setReports([]);
      }
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur lors du chargement des discussions.');
      setReports([]);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }

  useEffect(() => {
    fetchReports();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (loading) return <PageLoading />;

  const safeReports = reports || [];

  return (
    <div className="px-4 sm:px-8 py-8 max-w-5xl mx-auto space-y-6">
      {/* ── Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-[#0C1825] tracking-tight flex items-center gap-2.5">
            <MessageSquareText className="size-6 text-[#C0272D]" />
            Discussions & Rapports
          </h1>
          <p className="text-sm text-[#3D5166] mt-0.5">
            Fils de discussion avec les demandeurs pour les dossiers annulés ou verrouillés.
          </p>
        </div>

        <button
          type="button"
          onClick={() => fetchReports(true)}
          disabled={refreshing}
          className="size-9 rounded-xl bg-white border border-[#E0E4E9] shadow-xs flex items-center justify-center text-[#3D5166] hover:text-[#C0272D] transition-all shrink-0"
          title="Rafraîchir"
        >
          <RefreshCw className={`size-4 ${refreshing ? 'animate-spin' : ''}`} />
        </button>
      </div>

      <ErrorAlert message={error} />

      {/* ── Reports List ── */}
      {safeReports.length === 0 ? (
        <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs flex flex-col items-center gap-4 py-16">
          <div className="size-14 rounded-full bg-[#F4F6F8] flex items-center justify-center">
            <Inbox className="size-7 text-[#E0E4E9]" />
          </div>
          <div className="text-center">
            <p className="text-sm font-semibold text-[#0C1825]">Aucune discussion</p>
            <p className="text-xs text-[#3D5166] mt-1">Les discussions apparaîtront ici lorsque des dossiers seront annulés ou contestés.</p>
          </div>
        </div>
      ) : (
        <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs overflow-hidden divide-y divide-[#F4F6F8]">
          {safeReports.map((report) => (
            <Link
              key={report.id}
              href={`/reports/${report.credit_application_id}`}
              className="flex items-center justify-between gap-4 px-6 py-4 hover:bg-[#FDF2F2]/20 transition-colors group"
            >
              <div className="flex items-center gap-4 min-w-0">
                <div className="size-10 rounded-full bg-[#C0272D]/10 text-[#C0272D] flex items-center justify-center shrink-0">
                  <MessageSquareText className="size-5" />
                </div>
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="text-sm font-semibold text-[#0C1825] truncate">{report.applicant_name}</p>
                    <span className="text-[10px] font-mono text-[#3D5166] bg-[#F4F6F8] px-1.5 py-0.5 rounded border border-[#E0E4E9]">
                      Dossier #{report.credit_application_id}
                    </span>
                    {report.status && (
                      <span className={`text-[9px] font-bold px-1.5 py-0.2 rounded border ${statusColor(report.status)}`}>
                        {statusLabel(report.status)}
                      </span>
                    )}
                  </div>
                  <div className="flex items-center gap-2 mt-0.5">
                    <span className="text-[11px] text-[#3D5166]">
                      {report.messages_count} message{report.messages_count > 1 ? 's' : ''}
                    </span>
                    {report.last_message_at && (
                      <>
                        <span className="text-[#E0E4E9]">·</span>
                        <span className="text-[11px] text-[#3D5166] flex items-center gap-1">
                          <Clock className="size-3" />
                          {new Date(report.last_message_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                        </span>
                      </>
                    )}
                  </div>
                </div>
              </div>
              <ChevronRight className="size-4 text-[#E0E4E9] group-hover:text-[#C0272D] transition-colors shrink-0" />
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
