'use client';

import { useEffect, useState, useCallback, Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import Link from 'next/link';
import {
  Search,
  ChevronRight,
  ChevronLeft,
  FolderOpen,
  Filter,
  Inbox,
  Eye,
  RefreshCw,
} from 'lucide-react';
import { ErrorAlert } from '@/components/error-alert';
import { PageLoading } from '@/components/page-loading';
import { listStaffApplications, type StaffApplicationDto } from '@/lib/api/staff';
import { ApiError } from '@/lib/api/client';
import { getStaffToken } from '@/lib/auth/staff-token';
import { statusLabel, statusColor } from '@/lib/status-labels';
import type { ApplicationStatus } from '@/lib/api/credit-applications';

const STATUS_TABS: { value: ApplicationStatus | 'ALL'; label: string }[] = [
  { value: 'ALL', label: 'Tous' },
  { value: 'SUBMITTED', label: 'Soumis' },
  { value: 'STAFF_APPROVED', label: 'Approuvé (conseiller)' },
  { value: 'APPROVED', label: 'Approuvé' },
  { value: 'REJECTED', label: 'Rejeté' },
  { value: 'CANCELLED', label: 'Annulé' },
];

function ApplicationsListContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const initialStatus = searchParams.get('status') as ApplicationStatus | null;

  const [applications, setApplications] = useState<StaffApplicationDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<ApplicationStatus | 'ALL'>(initialStatus ?? 'ALL');
  const [searchQuery, setSearchQuery] = useState('');
  const [meta, setMeta] = useState<{ current_page: number; last_page: number; total: number } | null>(null);

  const fetchApplications = useCallback(async (status: ApplicationStatus | 'ALL', isRefresh = false) => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    if (isRefresh) setRefreshing(true);
    else setLoading(true);
    try {
      const filterStatus = status === 'ALL' ? undefined : status;
      const result = await listStaffApplications(filterStatus);
      setApplications(result.applications);
      setMeta(result.meta);
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur lors du chargement des dossiers.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [router]);

  useEffect(() => {
    fetchApplications(statusFilter);
  }, [fetchApplications, statusFilter]);

  // Client-side search filter
  const filteredApps = searchQuery.trim()
    ? applications.filter((app) => {
        const q = searchQuery.toLowerCase();
        const name = app.applicant?.name?.toLowerCase() ?? '';
        const demande = app.credit_request?.n_demande?.toLowerCase() ?? '';
        const email = app.applicant?.email?.toLowerCase() ?? '';
        return name.includes(q) || demande.includes(q) || email.includes(q) || String(app.id).includes(q);
      })
    : applications;

  if (loading && applications.length === 0) return <PageLoading />;

  return (
    <div className="px-4 sm:px-8 py-8 max-w-7xl mx-auto space-y-6">
      {/* ── Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-[#0C1825] tracking-tight flex items-center gap-2.5">
            <FolderOpen className="size-6 text-[#C0272D]" />
            Dossiers de Crédit
          </h1>
          <p className="text-sm text-[#3D5166] mt-0.5">
            Registre complet des demandes de crédit — {meta?.total ?? applications.length} dossier{(meta?.total ?? applications.length) > 1 ? 's' : ''}
          </p>
        </div>

        <button
          type="button"
          onClick={() => fetchApplications(statusFilter, true)}
          disabled={refreshing}
          className="size-9 rounded-xl bg-white border border-[#E0E4E9] shadow-xs flex items-center justify-center text-[#3D5166] hover:text-[#C0272D] hover:border-[#C0272D]/30 transition-all shrink-0"
          title="Rafraîchir"
        >
          <RefreshCw className={`size-4 ${refreshing ? 'animate-spin' : ''}`} />
        </button>
      </div>

      {/* ── Status Tabs ── */}
      <div className="flex items-center gap-2 overflow-x-auto pb-1">
        {STATUS_TABS.map((tab) => (
          <button
            key={tab.value}
            type="button"
            onClick={() => setStatusFilter(tab.value)}
            className={`px-4 py-2 text-xs font-semibold rounded-xl border transition-all whitespace-nowrap ${
              statusFilter === tab.value
                ? 'bg-[#C0272D] text-white border-[#C0272D] shadow-xs'
                : 'bg-white text-[#3D5166] border-[#E0E4E9] hover:bg-[#F4F6F8] hover:text-[#0C1825]'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* ── Search ── */}
      <div className="relative">
        <Search className="absolute left-4 top-1/2 -translate-y-1/2 size-4 text-[#3D5166]" />
        <input
          type="text"
          placeholder="Rechercher par nom, n° de demande, email…"
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          className="w-full pl-11 pr-4 py-3 text-sm bg-white rounded-xl border border-[#E0E4E9] shadow-xs placeholder:text-[#3D5166]/50 focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]/20 transition-colors"
        />
      </div>

      <ErrorAlert message={error} />

      {/* ── Applications Table ── */}
      {filteredApps.length === 0 ? (
        <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs flex flex-col items-center gap-4 py-16">
          <div className="size-14 rounded-full bg-[#F4F6F8] flex items-center justify-center">
            <Inbox className="size-7 text-[#E0E4E9]" />
          </div>
          <div className="text-center">
            <p className="text-sm font-semibold text-[#0C1825]">Aucun dossier trouvé</p>
            <p className="text-xs text-[#3D5166] mt-1">
              {searchQuery ? 'Essayez un autre terme de recherche.' : 'Aucun dossier avec ce statut.'}
            </p>
          </div>
        </div>
      ) : (
        <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs overflow-hidden">
          {/* Table Header */}
          <div className="hidden sm:grid sm:grid-cols-12 gap-4 px-6 py-3 bg-[#F4F6F8] border-b border-[#E0E4E9] text-[10px] font-bold uppercase tracking-wider text-[#3D5166]">
            <div className="col-span-2">N° Demande</div>
            <div className="col-span-3">Demandeur</div>
            <div className="col-span-2">Montant</div>
            <div className="col-span-2">Statut</div>
            <div className="col-span-2">Date</div>
            <div className="col-span-1"></div>
          </div>

          {/* Table Body */}
          <div className="divide-y divide-[#F4F6F8]">
            {filteredApps.map((app) => (
              <Link
                key={app.id}
                href={`/applications/${app.id}`}
                className="grid grid-cols-1 sm:grid-cols-12 gap-2 sm:gap-4 px-6 py-4 items-center hover:bg-[#FDF2F2]/20 transition-colors group"
              >
                {/* N° Demande */}
                <div className="sm:col-span-2">
                  <span className="text-xs font-mono font-bold text-[#0C1825]">
                    {app.credit_request?.n_demande ?? `#${app.id}`}
                  </span>
                </div>

                {/* Demandeur */}
                <div className="sm:col-span-3 flex items-center gap-3">
                  <div className="size-8 rounded-full bg-[#C0272D]/10 text-[#C0272D] flex items-center justify-center text-[10px] font-bold shrink-0">
                    {(app.applicant?.name ?? '??').split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase()}
                  </div>
                  <div className="min-w-0">
                    <p className="text-xs font-semibold text-[#0C1825] truncate">{app.applicant?.name ?? '—'}</p>
                    <p className="text-[10px] text-[#3D5166] truncate">{app.applicant?.email ?? ''}</p>
                  </div>
                </div>

                {/* Montant */}
                <div className="sm:col-span-2">
                  {app.credit_request?.montant_global_sollicite ? (
                    <span className="text-xs font-bold text-[#0C1825]">
                      {Number(app.credit_request.montant_global_sollicite).toLocaleString('fr-FR')} {app.credit_request.code_devise}
                    </span>
                  ) : (
                    <span className="text-xs text-[#3D5166]">—</span>
                  )}
                </div>

                {/* Statut */}
                <div className="sm:col-span-2">
                  <span className={`inline-flex items-center text-[10px] font-bold px-2.5 py-1 rounded-lg border ${statusColor(app.status)}`}>
                    {statusLabel(app.status)}
                  </span>
                </div>

                {/* Date */}
                <div className="sm:col-span-2">
                  <span className="text-xs text-[#3D5166]">
                    {app.submitted_at
                      ? new Date(app.submitted_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })
                      : app.created_at
                        ? new Date(app.created_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })
                        : '—'}
                  </span>
                </div>

                {/* Action */}
                <div className="sm:col-span-1 flex justify-end">
                  <ChevronRight className="size-4 text-[#E0E4E9] group-hover:text-[#C0272D] transition-colors" />
                </div>
              </Link>
            ))}
          </div>

          {/* Pagination */}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between px-6 py-3 border-t border-[#E0E4E9] bg-[#F4F6F8]/50">
              <p className="text-[11px] text-[#3D5166]">
                Page {meta.current_page} sur {meta.last_page} — {meta.total} résultat{meta.total > 1 ? 's' : ''}
              </p>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  disabled={meta.current_page <= 1}
                  className="px-3 py-1.5 text-xs font-semibold rounded-lg border border-[#E0E4E9] bg-white text-[#3D5166] hover:bg-[#F4F6F8] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                >
                  <ChevronLeft className="size-3.5" />
                </button>
                <button
                  type="button"
                  disabled={meta.current_page >= meta.last_page}
                  className="px-3 py-1.5 text-xs font-semibold rounded-lg border border-[#E0E4E9] bg-white text-[#3D5166] hover:bg-[#F4F6F8] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                >
                  <ChevronRight className="size-3.5" />
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

export default function ApplicationsListPage() {
  return (
    <Suspense fallback={<PageLoading />}>
      <ApplicationsListContent />
    </Suspense>
  );
}
