'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  ChevronRight,
  Inbox,
  Search,
} from 'lucide-react';
import { StaffHeader } from '@/components/staff-header';
import { ErrorAlert } from '@/components/error-alert';
import { listStaffApplications, type StaffApplicationDto } from '@/lib/api/staff';
import type { ApplicationStatus } from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getStaffToken, getStaffRole } from '@/lib/auth/staff-token';
import { InlineLoading } from '@/components/page-loading';

export default function StaffDashboardPage() {
  const router = useRouter();
  const [applications, setApplications] = useState<StaffApplicationDto[]>([]);
  const [filterStatus, setFilterStatus] = useState<ApplicationStatus | 'ALL' | 'FINAL_APPROVED'>('SUBMITTED');
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const role = getStaffRole() ?? 'staff';

  const fetchApplications = useCallback(async (showLoading = false) => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    if (showLoading) setLoading(true);
    setError(null);
    const statuses = filterStatus === 'FINAL_APPROVED'
      ? (['APPROVED', 'APPOINTMENT_PROPOSED', 'APPOINTMENT_CONFIRMED', 'APPOINTMENT_LOCKED'] as const)
      : filterStatus === 'ALL' ? undefined : filterStatus;
    try {
      const result = await listStaffApplications(statuses);
      setApplications(result.applications);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Impossible de charger les dossiers.');
    } finally {
      setLoading(false);
    }
  }, [filterStatus, router]);

  useEffect(() => {
    queueMicrotask(() => void fetchApplications(true));
    const refresh = () => {
      if (document.visibilityState === 'visible') void fetchApplications();
    };
    const interval = window.setInterval(refresh, 10_000);
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', refresh);
    return () => {
      window.clearInterval(interval);
      window.removeEventListener('focus', refresh);
      document.removeEventListener('visibilitychange', refresh);
    };
  }, [fetchApplications]);

  const filteredApps = applications.filter((app) => {
    if (!searchTerm) return true;
    const term = searchTerm.toLowerCase();
    const ref = (app.credit_request?.n_demande ?? '').toLowerCase();
    const name = (app.applicant?.name ?? '').toLowerCase();
    const type = (app.credit_request?.type_demande ?? '').toLowerCase();
    return ref.includes(term) || name.includes(term) || type.includes(term);
  });

  return (
    <div className="staff-page text-[#1E2D3D]">
      <StaffHeader role={role} />
      <main id="main" className="staff-main space-y-6">
        {/* Page Hero Header */}
        <section className="staff-page-hero flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
          <div className="space-y-1">
            <p className="overline">Espace Instruction & Décision</p>
            <h1 className="font-display text-3xl font-light text-[#0C1825]">
              File d&apos;Instruction des Demandes de Crédit
            </h1>
            <p className="text-xs text-[#3D5166]">
              Examinez la conformité des pièces justificatives, les plans de financement et prononcez les décisions bancaires.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <span className="text-xs font-bold text-[#0C1825] bg-white px-3 py-1.5 rounded-lg border border-[#E0E4E9] shadow-xs">
              {filteredApps.length} dossier{filteredApps.length > 1 ? 's' : ''} affiché{filteredApps.length > 1 ? 's' : ''}
            </span>
          </div>
        </section>

        {/* Filter & Search Bar */}
        <div className="figma-card p-4 bg-white flex flex-col md:flex-row items-center justify-between gap-4">
          {/* Status Tabs */}
          <div className="flex flex-wrap items-center gap-1.5 w-full md:w-auto">
            <button
              type="button"
              onClick={() => setFilterStatus('SUBMITTED')}
              className={`text-xs font-semibold px-3.5 py-1.5 rounded-lg transition-colors ${
                filterStatus === 'SUBMITTED'
                  ? 'bg-[#C0272D] text-white'
                  : 'bg-[#F4F6F8] text-[#3D5166] hover:bg-gray-200'
              }`}
            >
              À instruire (Soumis)
            </button>
            <button
              type="button"
              onClick={() => setFilterStatus('STAFF_APPROVED')}
              className={`text-xs font-semibold px-3.5 py-1.5 rounded-lg transition-colors ${
                filterStatus === 'STAFF_APPROVED'
                  ? 'bg-[#C0272D] text-white'
                  : 'bg-[#F4F6F8] text-[#3D5166] hover:bg-gray-200'
              }`}
            >
              Accord Staff
            </button>
            <button
              type="button"
              onClick={() => setFilterStatus('FINAL_APPROVED')}
              className={`text-xs font-semibold px-3.5 py-1.5 rounded-lg transition-colors ${
                filterStatus === 'FINAL_APPROVED'
                  ? 'bg-[#C0272D] text-white'
                  : 'bg-[#F4F6F8] text-[#3D5166] hover:bg-gray-200'
              }`}
            >
              Validés Définitifs
            </button>
            <button
              type="button"
              onClick={() => setFilterStatus('ALL')}
              className={`text-xs font-semibold px-3.5 py-1.5 rounded-lg transition-colors ${
                filterStatus === 'ALL'
                  ? 'bg-[#C0272D] text-white'
                  : 'bg-[#F4F6F8] text-[#3D5166] hover:bg-gray-200'
              }`}
            >
              Tous les statuts
            </button>
          </div>

          {/* Search Input */}
          <div className="relative w-full md:w-72">
            <Search className="size-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              placeholder="Rechercher par N° ou Nom…"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg pl-9 pr-3 py-2 text-[#0C1825] focus:outline-none focus:border-[#C0272D]"
            />
          </div>
        </div>

        <ErrorAlert message={error} />

        {loading ? (
          <div className="figma-card p-12 bg-white text-center">
            <InlineLoading />
          </div>
        ) : !error && filteredApps.length === 0 ? (
          <div className="figma-card p-12 bg-white text-center space-y-3">
            <Inbox className="size-10 text-gray-400 mx-auto" />
            <p className="font-semibold text-[#0C1825] text-sm">Aucun dossier dans cette file d&apos;attente</p>
            <p className="text-xs text-[#3D5166] max-w-sm mx-auto">
              Tous les dossiers soumis pour ce statut ont été traités.
            </p>
          </div>
        ) : (
          <div className="staff-table-shell">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs border-collapse">
                <thead>
                  <tr className="border-b border-[#E0E4E9] bg-[#F4F6F8] text-[#3D5166] uppercase text-[10px] font-bold tracking-wider">
                    <th className="py-3.5 px-4">N° Demande</th>
                    <th className="py-3.5 px-4">Demandeur</th>
                    <th className="py-3.5 px-4">Type de Crédit</th>
                    <th className="py-3.5 px-4">Montant Sollicité</th>
                    <th className="py-3.5 px-4">Date de Dépôt</th>
                    <th className="py-3.5 px-4">Statut</th>
                    <th className="py-3.5 px-4 text-right">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#E0E4E9]">
                  {filteredApps.map((app) => (
                    <tr
                      key={app.id}
                      onClick={() => router.push(`/dashboard/${app.id}`)}
                      className="hover:bg-[#FDF2F2]/40 transition-colors cursor-pointer group"
                    >
                      <td className="py-4 px-4 font-mono font-bold text-[#C0272D]">
                        {app.credit_request?.n_demande ?? `#${app.id}`}
                      </td>
                      <td className="py-4 px-4">
                        <div className="font-semibold text-[#0C1825]">{app.applicant?.name ?? '—'}</div>
                        <div className="text-[11px] text-[#3D5166]">{app.applicant?.email ?? ''}</div>
                      </td>
                      <td className="py-4 px-4 text-[#0C1825] font-medium">
                        {app.credit_request?.type_demande || 'Crédit Professionnel'}
                      </td>
                      <td className="py-4 px-4 font-mono font-bold text-[#0C1825]">
                        {app.credit_request?.montant_global_sollicite
                          ? `${new Intl.NumberFormat('fr-TN').format(Number(app.credit_request.montant_global_sollicite))} ${app.credit_request.code_devise ?? 'TND'}`
                          : '—'}
                      </td>
                      <td className="py-4 px-4 text-[#3D5166]">
                        {app.submitted_at
                          ? new Date(app.submitted_at).toLocaleDateString('fr-FR')
                          : new Date(app.created_at).toLocaleDateString('fr-FR')}
                      </td>
                      <td className="py-4 px-4">
                        <span className={`badge ${statusToBadgeClass(app.status)} text-[10px]`}>
                          {statusToFrenchLabel(app.status)}
                        </span>
                      </td>
                      <td className="py-4 px-4 text-right">
                        <Link
                          href={`/dashboard/${app.id}`}
                          onClick={(event) => event.stopPropagation()}
                          aria-label={`Examiner le dossier ${app.credit_request?.n_demande ?? app.id}`}
                          className="btn-red px-2.5 py-1 text-[11px] opacity-90 group-hover:opacity-100"
                        >
                          <span>Examiner</span>
                          <ChevronRight className="size-3" />
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </main>
    </div>
  );
}

function statusToBadgeClass(status: string) {
  switch (status) {
    case 'SUBMITTED':
      return 'badge-warning';
    case 'STAFF_APPROVED':
    case 'APPROVED':
    case 'APPOINTMENT_CONFIRMED':
      return 'badge-success';
    case 'REJECTED':
    case 'STAFF_REJECTED':
    case 'CANCELLED':
      return 'badge-danger';
    default:
      return 'badge-neutral';
  }
}

function statusToFrenchLabel(status: string) {
  switch (status) {
    case 'SUBMITTED':
      return 'En attente de révision';
    case 'STAFF_APPROVED':
      return 'Accordé par le conseiller';
    case 'APPROVED':
      return 'Validé définitif';
    case 'REJECTED':
    case 'STAFF_REJECTED':
      return 'Dossier rejeté';
    case 'CANCELLED':
      return 'Annulé';
    case 'APPOINTMENT_PROPOSED':
      return 'Rendez-vous proposé';
    case 'APPOINTMENT_CONFIRMED':
      return 'Rendez-vous confirmé';
    case 'APPOINTMENT_LOCKED':
      return 'Rendez-vous bloqué';
    default:
      return status;
  }
}
