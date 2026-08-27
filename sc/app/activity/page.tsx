'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import {
  Search,
  Download,
  ChevronLeft,
  ChevronRight,
  Eye,
  Laptop,
  RefreshCw,
} from 'lucide-react';
import {
  getSecurityActivity,
  exportSecurityActivity,
  AuditEventItem,
} from '@/lib/api/security';
import { formatDate } from '@/lib/utils';
import { getErrorMessage } from '@/lib/api/client';
import { escapeCsvCell, saveBlob } from '@/lib/download';
import { StatusMessage } from '@/components/status-message';
import { AccessibleModal } from '@/components/accessible-modal';

export default function SecurityActivityPage() {
  const [events, setEvents] = useState<AuditEventItem[]>([]);
  const [availableActions, setAvailableActions] = useState<string[]>([]);
  const [meta, setMeta] = useState<{ current_page: number; last_page: number; total: number; per_page: number }>({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 25,
  });

  const [search, setSearch] = useState('');
  const [appliedSearch, setAppliedSearch] = useState('');
  const [actionFilter, setActionFilter] = useState('');
  const [actorTypeFilter, setActorTypeFilter] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [selectedDeviceEvent, setSelectedDeviceEvent] = useState<AuditEventItem | null>(null);
  const [exporting, setExporting] = useState(false);

  const fetchActivity = useCallback(async (requestedPage = page) => {
    setLoading(true);
    setError(null);
    try {
      const res = await getSecurityActivity({
        search: appliedSearch || undefined,
        action: actionFilter || undefined,
        actor_type: actorTypeFilter || undefined,
        page: requestedPage,
        per_page: 25,
      });
      setEvents(res.items);
      setMeta(res.meta);
      setAvailableActions(res.available_actions);
    } catch (fetchError: unknown) {
      setError(getErrorMessage(fetchError));
    } finally {
      setLoading(false);
    }
  }, [actionFilter, actorTypeFilter, appliedSearch, page]);

  useEffect(() => {
    queueMicrotask(() => void fetchActivity());
  }, [fetchActivity]);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (page === 1 && appliedSearch === search) {
      void fetchActivity(1);
      return;
    }
    setPage(1);
    setAppliedSearch(search);
  };

  const handleExport = async (format: 'json' | 'csv') => {
    setExporting(true);
    try {
      const res = await exportSecurityActivity({
        format,
        action: actionFilter || undefined,
        search: appliedSearch || undefined,
        actor_type: actorTypeFilter || undefined,
      });

      if (format === 'json') {
        const blob = new Blob([JSON.stringify(res.records, null, 2)], { type: 'application/json' });
        saveBlob(blob, `audit-export-${new Date().toISOString().slice(0, 10)}.json`);
      } else {
        const headers = ['ID', 'Action', 'Date', 'Acteur', 'Type Acteur', 'IP', 'User Agent'];
        const rows = res.records.map((record) => [
          record.id,
          record.action,
          record.created_at,
          record.actor?.name || 'System',
          record.actor?.type || 'system',
          record.ip_address,
          record.user_agent,
        ].map(escapeCsvCell));
        const csvContent = [headers.map(escapeCsvCell).join(','), ...rows.map((row) => row.join(','))].join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        saveBlob(blob, `audit-export-${new Date().toISOString().slice(0, 10)}.csv`);
      }
    } catch (exportError: unknown) {
      setError(`Erreur d’export : ${getErrorMessage(exportError, 'Échec de l’exportation')}`);
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="sc-page">
      {/* ── Header ── */}
      <div className="sc-page-header flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="text-xl font-bold text-[#0C1825] tracking-tight">Journal d’Audit Global (SOC)</h1>
            <span className="px-2.5 py-0.5 text-xs font-semibold bg-[#FDF2F2] text-[#C0272D] border border-[#F5C2C4] rounded-full">
              {meta.total} enregistrements
            </span>
          </div>
          <p className="text-xs text-[#3D5166] mt-1">
            Traçabilité intégrale des accès, authentifications, scans et mutations de données
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            onClick={() => handleExport('csv')}
            disabled={exporting || events.length === 0}
            className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-xs font-medium text-[#3D5166] transition-colors disabled:opacity-50 shadow-2xs"
          >
            <Download className="size-3.5" />
            <span>Export CSV</span>
          </button>
          <button
            onClick={() => handleExport('json')}
            disabled={exporting || events.length === 0}
            className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-xs font-medium text-[#3D5166] transition-colors disabled:opacity-50 shadow-2xs"
          >
            <Download className="size-3.5" />
            <span>Export JSON</span>
          </button>
          <button
            type="button"
            onClick={() => void fetchActivity()}
            aria-label="Actualiser le journal"
            disabled={loading}
            className="p-2 rounded-xl bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-[#3D5166] transition-colors disabled:opacity-50 shadow-2xs"
          >
            <RefreshCw className={`size-3.5 ${loading ? 'animate-spin' : ''}`} />
          </button>
        </div>
      </div>

      <StatusMessage message={error} />

      {/* ── Filter Bar ── */}
      <div className="p-4 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs flex flex-col md:flex-row gap-3 items-center justify-between text-xs">
        <form onSubmit={handleSearchSubmit} className="w-full md:w-80 relative">
          <Search className="size-4 text-[#8C9BAE] absolute left-3.5 top-2.5" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Rechercher action, IP, acteur..."
            className="w-full pl-10 pr-3 py-2 bg-[#F8FAFC] border border-[#E0E4E9] rounded-xl text-[#0C1825] placeholder:text-[#8C9BAE] focus:outline-none focus:border-[#C0272D]"
          />
        </form>

        <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
          <select
            value={actionFilter}
            onChange={(e) => {
              setActionFilter(e.target.value);
              setPage(1);
            }}
            className="px-3 py-2 bg-white border border-[#E0E4E9] rounded-xl text-[#0C1825] focus:outline-none focus:border-[#C0272D]"
          >
            <option value="">Toutes les actions</option>
            {availableActions.map((act) => (
              <option key={act} value={act}>
                {act}
              </option>
            ))}
          </select>

          <select
            value={actorTypeFilter}
            onChange={(e) => {
              setActorTypeFilter(e.target.value);
              setPage(1);
            }}
            className="px-3 py-2 bg-white border border-[#E0E4E9] rounded-xl text-[#0C1825] focus:outline-none focus:border-[#C0272D]"
          >
            <option value="">Tous les types d’acteurs</option>
            <option value="staff">Personnel (Staff)</option>
            <option value="customer">Clients (Customer)</option>
            <option value="system">Système (System)</option>
          </select>
        </div>
      </div>

      {/* ── Main Activity Table ── */}
      <div className="bg-white border border-[#E0E4E9] rounded-2xl shadow-xs overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-[#F8FAFC] border-b border-[#E0E4E9] text-[#3D5166] uppercase text-[10px] font-bold tracking-wider">
              <tr>
                <th className="py-3 px-4">Horodatage</th>
                <th className="py-3 px-4">Action</th>
                <th className="py-3 px-4">Acteur</th>
                <th className="py-3 px-4">Adresse IP</th>
                <th className="py-3 px-4">Dossier</th>
                <th className="py-3 px-4 text-right">Détails</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#E0E4E9]">
              {loading && events.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-500">
                    Chargement des journaux d’audit…
                  </td>
                </tr>
              ) : events.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-500">
                    Aucune donnée
                  </td>
                </tr>
              ) : (
                events.map((evt) => (
                  <tr key={evt.id} className="hover:bg-[#F8FAFC] transition-colors">
                    <td className="py-3 px-4 text-[#3D5166] whitespace-nowrap">
                      {formatDate(evt.created_at)}
                    </td>
                    <td className="py-3 px-4 font-semibold">
                      <span className="px-2.5 py-0.5 rounded-lg bg-[#FDF2F2] border border-[#F5C2C4] text-[#C0272D] font-mono text-[11px]">
                        {evt.action}
                      </span>
                    </td>
                    <td className="py-3 px-4">
                      <div className="text-[#0C1825] font-semibold">{evt.actor.name}</div>
                      <div className="text-[10px] text-[#3D5166] uppercase">{evt.actor.type}</div>
                    </td>
                    <td className="py-3 px-4 text-[#0C1825]">
                      {evt.ip_address ? (
                        <span className="font-mono">{evt.ip_address}</span>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="py-3 px-4 text-[#3D5166]">
                      {evt.credit_application_id ? (
                        <span className="font-bold text-[#C0272D]">#{evt.credit_application_id}</span>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="py-3 px-4 text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        {evt.device && (
                          <button
                            onClick={() => setSelectedDeviceEvent(evt)}
                            title="Inspecter empreinte appareil"
                            className="p-1.5 rounded-lg bg-[#F4F6F8] hover:bg-[#E0E4E9] text-[#3D5166] transition-colors"
                          >
                            <Laptop className="size-3.5" />
                          </button>
                        )}
                        <Link
                          href={`/activity/${evt.id}`}
                          className="p-1.5 rounded-lg bg-[#FDF2F2] hover:bg-[#FCE8E8] text-[#C0272D] transition-colors inline-block"
                          title="Détails complets"
                        >
                          <Eye className="size-3.5" />
                        </Link>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* ── Pagination ── */}
        {meta.last_page > 1 && (
          <div className="p-4 border-t border-[#E0E4E9] bg-[#F8FAFC] flex items-center justify-between text-xs">
            <div className="text-[#3D5166]">
              Page {meta.current_page} sur {meta.last_page} ({meta.total} résultats)
            </div>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1 || loading}
                className="p-2 rounded-lg bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-[#3D5166] disabled:opacity-40"
              >
                <ChevronLeft className="size-3.5" />
              </button>
              <button
                onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                disabled={page >= meta.last_page || loading}
                className="p-2 rounded-lg bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-[#3D5166] disabled:opacity-40"
              >
                <ChevronRight className="size-3.5" />
              </button>
            </div>
          </div>
        )}
      </div>

      {/* ── Device Telemetry Modal ── */}
      {selectedDeviceEvent && (
        <AccessibleModal
          open
          title="Empreinte Appareil & Réseau"
          description={`Événement #${selectedDeviceEvent.id} — ${selectedDeviceEvent.action}`}
          onClose={() => setSelectedDeviceEvent(null)}
        >
          <div className="space-y-4 text-xs">
            <div className="space-y-2.5 bg-[#F8FAFC] p-4 rounded-xl border border-[#E0E4E9]">
              <div className="flex justify-between">
                <span className="text-[#3D5166]">Adresse IP :</span>
                <span className="font-mono font-bold text-[#0C1825]">{selectedDeviceEvent.ip_address || '—'}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-[#3D5166]">Système (OS) :</span>
                <span className="font-semibold text-[#0C1825]">{selectedDeviceEvent.device?.os || 'Non détecté'}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-[#3D5166]">Navigateur :</span>
                <span className="font-semibold text-[#0C1825]">{selectedDeviceEvent.device?.browser || 'Non détecté'}</span>
              </div>
              {selectedDeviceEvent.user_agent && (
                <div className="pt-2 border-t border-[#E0E4E9]">
                  <div className="text-[#3D5166] mb-1">User Agent :</div>
                  <div className="text-[11px] text-[#0C1825] break-all bg-white p-2.5 rounded-lg border border-[#E0E4E9] font-mono">
                    {selectedDeviceEvent.user_agent}
                  </div>
                </div>
              )}
            </div>

            <div className="flex justify-end">
              <button
                type="button"
                onClick={() => setSelectedDeviceEvent(null)}
                className="secondary-button"
              >
                Fermer
              </button>
            </div>
          </div>
        </AccessibleModal>
      )}
    </div>
  );
}
