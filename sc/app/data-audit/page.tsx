'use client';

import { useCallback, useState, useEffect } from 'react';
import {
  FileText,
  Calendar,
  Layers,
  Search,
  ChevronLeft,
  ChevronRight,
  RefreshCw,
} from 'lucide-react';
import {
  getSecurityApplications,
  getSecurityDocuments,
  getSecurityAppointments,
  PaginatedResponse,
  SecurityApplicationItem,
  SecurityDocumentItem,
  SecurityAppointmentItem,
} from '@/lib/api/security';
import { formatDate } from '@/lib/utils';
import { getErrorMessage } from '@/lib/api/client';
import { StatusMessage } from '@/components/status-message';

export default function SecurityDataAuditPage() {
  const [tab, setTab] = useState<'applications' | 'documents' | 'appointments'>('applications');
  const [applications, setApplications] = useState<PaginatedResponse<SecurityApplicationItem> | null>(null);
  const [documents, setDocuments] = useState<PaginatedResponse<SecurityDocumentItem> | null>(null);
  const [appointments, setAppointments] = useState<PaginatedResponse<SecurityAppointmentItem> | null>(null);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [appliedSearch, setAppliedSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchData = useCallback(async (showLoading = true) => {
    if (showLoading) setLoading(true);
    setError(null);
    try {
      if (tab === 'applications') {
        const res = await getSecurityApplications({ page, search: appliedSearch || undefined });
        setApplications(res);
      } else if (tab === 'documents') {
        const res = await getSecurityDocuments(page);
        setDocuments(res);
      } else if (tab === 'appointments') {
        const res = await getSecurityAppointments(page);
        setAppointments(res);
      }
    } catch (fetchError: unknown) {
      setError(getErrorMessage(fetchError));
    } finally {
      setLoading(false);
    }
  }, [appliedSearch, page, tab]);

  useEffect(() => {
    queueMicrotask(() => void fetchData());
    const refresh = () => {
      if (document.visibilityState === 'visible') void fetchData(false);
    };
    const interval = window.setInterval(refresh, 10_000);
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', refresh);
    return () => {
      window.clearInterval(interval);
      window.removeEventListener('focus', refresh);
      document.removeEventListener('visibilitychange', refresh);
    };
  }, [fetchData]);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (page === 1 && appliedSearch === search) {
      void fetchData();
      return;
    }
    setPage(1);
    setAppliedSearch(search);
  };

  return (
    <div className="sc-page">
      {/* ── Header ── */}
      <div className="sc-page-header flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="text-xl font-bold text-[#0C1825] tracking-tight">
              Audit des Données Métier & Conformité
            </h1>
            <span className="px-2.5 py-0.5 text-xs font-semibold bg-[#FDF2F2] text-[#C0272D] border border-[#F5C2C4] rounded-full">
              Inspection Réglementaire
            </span>
          </div>
          <p className="text-xs text-[#3D5166] mt-1">
            Inspection en lecture seule des demandes de crédit, métadonnées de pièces justificatives et rendez-vous
          </p>
        </div>

        <button
          onClick={() => void fetchData()}
          disabled={loading}
          className="p-2 rounded-xl bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-[#3D5166] transition-colors disabled:opacity-50 shadow-2xs"
        >
          <RefreshCw className={`size-3.5 ${loading ? 'animate-spin' : ''}`} />
        </button>
      </div>

      <StatusMessage message={error} />

      {/* ── Tabs & Search Bar ── */}
      <div className="p-4 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs flex flex-col md:flex-row gap-3 items-center justify-between text-xs">
        <div role="tablist" aria-label="Catégorie de données" className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            role="tab"
            aria-selected={tab === 'applications'}
            onClick={() => {
              setTab('applications');
              setPage(1);
            }}
            className={`px-4 py-2 rounded-xl font-semibold transition-colors flex items-center gap-1.5 ${
              tab === 'applications'
                ? 'bg-[#C0272D] text-white shadow-xs'
                : 'bg-[#F4F6F8] text-[#3D5166] hover:text-[#0C1825]'
            }`}
          >
            <Layers className="size-3.5" />
            <span>Dossiers de Crédit</span>
          </button>

          <button
            type="button"
            role="tab"
            aria-selected={tab === 'documents'}
            onClick={() => {
              setTab('documents');
              setPage(1);
            }}
            className={`px-4 py-2 rounded-xl font-semibold transition-colors flex items-center gap-1.5 ${
              tab === 'documents'
                ? 'bg-[#C0272D] text-white shadow-xs'
                : 'bg-[#F4F6F8] text-[#3D5166] hover:text-[#0C1825]'
            }`}
          >
            <FileText className="size-3.5" />
            <span>Documents & Analyse IA</span>
          </button>

          <button
            type="button"
            role="tab"
            aria-selected={tab === 'appointments'}
            onClick={() => {
              setTab('appointments');
              setPage(1);
            }}
            className={`px-4 py-2 rounded-xl font-semibold transition-colors flex items-center gap-1.5 ${
              tab === 'appointments'
                ? 'bg-[#C0272D] text-white shadow-xs'
                : 'bg-[#F4F6F8] text-[#3D5166] hover:text-[#0C1825]'
            }`}
          >
            <Calendar className="size-3.5" />
            <span>Rendez-vous Agence</span>
          </button>
        </div>

        {tab === 'applications' && (
          <form onSubmit={handleSearchSubmit} className="relative w-full md:w-64">
            <Search className="size-4 text-[#8C9BAE] absolute left-3.5 top-2.5" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Rechercher demandeur, N°..."
              className="w-full pl-10 pr-3 py-2 bg-[#F8FAFC] border border-[#E0E4E9] rounded-xl text-[#0C1825] placeholder:text-[#8C9BAE] focus:outline-none focus:border-[#C0272D]"
            />
          </form>
        )}
      </div>

      {/* ── Main Data Table ── */}
      <div className="bg-white border border-[#E0E4E9] rounded-2xl shadow-xs overflow-hidden">
        <div className="overflow-x-auto">
          {tab === 'applications' && (
            <table className="w-full text-left text-xs">
              <thead className="bg-[#F8FAFC] border-b border-[#E0E4E9] text-[#3D5166] uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th className="py-3 px-4">ID / N° Demande</th>
                  <th className="py-3 px-4">Demandeur</th>
                  <th className="py-3 px-4">Montant Sollicité</th>
                  <th className="py-3 px-4">Agence</th>
                  <th className="py-3 px-4">Statut</th>
                  <th className="py-3 px-4">Date Dépôt</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#E0E4E9]">
                {loading && !applications ? (
                  <tr>
                    <td colSpan={6} className="py-12 text-center text-slate-500">
                      Chargement des dossiers...
                    </td>
                  </tr>
                ) : applications?.items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-12 text-center text-slate-500">
                      Aucune donnée
                    </td>
                  </tr>
                ) : (
                  applications?.items.map((app) => (
                    <tr key={app.id} className="hover:bg-[#F8FAFC] transition-colors">
                      <td className="py-3 px-4 font-bold text-[#C0272D]">
                        #{app.id} {app.n_demande && <span className="text-[#3D5166] font-normal font-mono">({app.n_demande})</span>}
                      </td>
                      <td className="py-3 px-4">
                        <div className="text-[#0C1825] font-semibold">{app.user?.name || 'N/A'}</div>
                        <div className="text-[11px] text-[#3D5166]">{app.user?.email}</div>
                      </td>
                      <td className="py-3 px-4 text-[#0C1825] font-bold">
                        {app.montant ? `${Number(app.montant).toLocaleString()} TND` : '—'}
                      </td>
                      <td className="py-3 px-4 text-[#3D5166]">
                        {app.branch ? `${app.branch.name} (${app.branch.ville})` : '—'}
                      </td>
                      <td className="py-3 px-4">
                        <span className="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F4F6F8] border border-[#E0E4E9] text-[#3D5166]">
                          {app.status}
                        </span>
                      </td>
                      <td className="py-3 px-4 text-[#3D5166] whitespace-nowrap">
                        {formatDate(app.submitted_at || app.created_at)}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          )}

          {tab === 'documents' && (
            <table className="w-full text-left text-xs">
              <thead className="bg-[#F8FAFC] border-b border-[#E0E4E9] text-[#3D5166] uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th className="py-3 px-4">Dossier</th>
                  <th className="py-3 px-4">Demandeur</th>
                  <th className="py-3 px-4">Type de Pièce</th>
                  <th className="py-3 px-4">Fichier & Taille</th>
                  <th className="py-3 px-4">Contrôle IA</th>
                  <th className="py-3 px-4">Date Dépôt</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#E0E4E9]">
                {loading && !documents ? (
                  <tr>
                    <td colSpan={6} className="py-12 text-center text-slate-500">
                      Chargement des documents...
                    </td>
                  </tr>
                ) : documents?.items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-12 text-center text-slate-500">
                      Aucune donnée
                    </td>
                  </tr>
                ) : (
                  documents?.items.map((doc) => (
                    <tr key={doc.id} className="hover:bg-[#F8FAFC] transition-colors">
                      <td className="py-3 px-4 font-bold text-[#C0272D]">#{doc.credit_application_id}</td>
                      <td className="py-3 px-4 text-[#0C1825] font-semibold">{doc.applicant_name}</td>
                      <td className="py-3 px-4 text-[#3D5166]">{doc.document_type}</td>
                      <td className="py-3 px-4 text-[#3D5166]">
                        <div className="font-semibold text-[#0C1825] truncate max-w-[200px]">{doc.original_filename}</div>
                        <div className="text-[11px] text-[#3D5166]">
                          {(doc.size_bytes / 1024).toFixed(1)} Ko • {doc.mime_type}
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        {doc.ai_verified_at ? (
                          <div className="flex items-center gap-1.5">
                            <span
                              className={`px-2 py-0.5 rounded-full text-[10px] font-bold ${
                                doc.ai_is_valid
                                  ? 'bg-emerald-100 text-emerald-700'
                                  : 'bg-red-100 text-red-700'
                              }`}
                            >
                              {doc.ai_is_valid ? 'VALIDE' : 'REJETÉ'} ({doc.ai_confidence}%)
                            </span>
                          </div>
                        ) : (
                          <span className="text-slate-400">Non analysé</span>
                        )}
                      </td>
                      <td className="py-3 px-4 text-[#3D5166] whitespace-nowrap">
                        {formatDate(doc.created_at)}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          )}

          {tab === 'appointments' && (
            <table className="w-full text-left text-xs">
              <thead className="bg-[#F8FAFC] border-b border-[#E0E4E9] text-[#3D5166] uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th className="py-3 px-4">Dossier</th>
                  <th className="py-3 px-4">Demandeur</th>
                  <th className="py-3 px-4">Date Prévue</th>
                  <th className="py-3 px-4">Heure</th>
                  <th className="py-3 px-4">Agence</th>
                  <th className="py-3 px-4">Statut</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#E0E4E9]">
                {loading && !appointments ? (
                  <tr>
                    <td colSpan={6} className="py-12 text-center text-slate-500">
                      Chargement des rendez-vous...
                    </td>
                  </tr>
                ) : appointments?.items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-12 text-center text-slate-500">
                      Aucune donnée
                    </td>
                  </tr>
                ) : (
                  appointments?.items.map((apt) => (
                    <tr key={apt.id} className="hover:bg-[#F8FAFC] transition-colors">
                      <td className="py-3 px-4 font-bold text-[#C0272D]">#{apt.credit_application_id}</td>
                      <td className="py-3 px-4 text-[#0C1825] font-semibold">{apt.applicant_name}</td>
                      <td className="py-3 px-4 text-[#0C1825] font-bold">{apt.scheduled_date}</td>
                      <td className="py-3 px-4 text-[#3D5166]">{apt.scheduled_time}</td>
                      <td className="py-3 px-4 text-[#3D5166]">
                        {apt.branch ? `${apt.branch.name} (${apt.branch.ville})` : '—'}
                      </td>
                      <td className="py-3 px-4">
                        <span className="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F4F6F8] border border-[#E0E4E9] text-[#3D5166]">
                          {apt.status}
                        </span>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          )}
        </div>

        {/* ── Pagination ── */}
        {((tab === 'applications' && (applications?.meta.last_page ?? 1) > 1) ||
          (tab === 'documents' && (documents?.meta.last_page ?? 1) > 1) ||
          (tab === 'appointments' && (appointments?.meta.last_page ?? 1) > 1)) && (
          <div className="p-4 border-t border-[#E0E4E9] bg-[#F8FAFC] flex items-center justify-between text-xs">
            <div className="text-[#3D5166]">Page {page}</div>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                aria-label="Page précédente"
                disabled={page <= 1 || loading}
                className="p-2 rounded-lg bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-[#3D5166] disabled:opacity-40"
              >
                <ChevronLeft className="size-3.5" />
              </button>
              <button
                onClick={() => setPage((p) => Math.min(
                  tab === 'applications'
                    ? applications?.meta.last_page ?? 1
                    : tab === 'documents'
                      ? documents?.meta.last_page ?? 1
                      : appointments?.meta.last_page ?? 1,
                  p + 1
                ))}
                disabled={loading || page >= (
                  tab === 'applications'
                    ? applications?.meta.last_page ?? 1
                    : tab === 'documents'
                      ? documents?.meta.last_page ?? 1
                      : appointments?.meta.last_page ?? 1
                )}
                aria-label="Page suivante"
                className="p-2 rounded-lg bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-[#3D5166] disabled:opacity-40"
              >
                <ChevronRight className="size-3.5" />
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
