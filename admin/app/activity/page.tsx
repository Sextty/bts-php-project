'use client';

import { useEffect, useState, useCallback } from 'react';
import { useRouter } from 'next/navigation';
import {
  Activity,
  RefreshCw,
  Shield,
  FolderOpen,
  FileCheck,
  Gavel,
  Calendar,
  MessageSquare,
  Search,
  Download,
  Eye,
  Globe,
  Clock,
  ChevronDown,
  X,
} from 'lucide-react';
import { ErrorAlert } from '@/components/error-alert';
import { PageLoading } from '@/components/page-loading';
import {
  getActivityLogs,
  getTraffic,
  type ActivityLogDto,
  type TrafficDto,
} from '@/lib/api/staff-insights';
import { ApiError } from '@/lib/api/client';
import { getStaffToken } from '@/lib/auth/staff-token';

const PERIOD_OPTIONS = [
  { value: 7, label: '7j' },
  { value: 14, label: '14j' },
  { value: 30, label: '30j' },
  { value: 90, label: '90j' },
] as const;

const CATEGORY_FILTERS = [
  { id: 'all', label: 'Toutes', icon: Activity },
  { id: 'auth', label: 'Sécurité & Auth', icon: Shield },
  { id: 'credit', label: 'Dossiers de Crédit', icon: FolderOpen },
  { id: 'docs', label: 'Pièces & IA', icon: FileCheck },
  { id: 'decision', label: 'Décisions', icon: Gavel },
  { id: 'appointment', label: 'Rendez-vous', icon: Calendar },
  { id: 'report', label: 'Discussion', icon: MessageSquare },
] as const;

function categorize(action: string): string {
  if (action.includes('login') || action.includes('logout') || action.includes('otp') || action.includes('register') || action.includes('password') || action.includes('google')) return 'auth';
  if (action.includes('document') || action.includes('verification') || action.includes('ai_')) return 'docs';
  if (action.includes('approve') || action.includes('reject') || action.includes('cancel') || action.includes('decision')) return 'decision';
  if (action.includes('appointment') || action.includes('rdv')) return 'appointment';
  if (action.includes('report') || action.includes('message') || action.includes('chat')) return 'report';
  if (action.includes('credit') || action.includes('application') || action.includes('validation') || action.includes('submit') || action.includes('step')) return 'credit';
  return 'credit';
}

function actionColor(action: string): string {
  const cat = categorize(action);
  switch (cat) {
    case 'auth': return 'bg-red-50 text-red-700 border-red-200';
    case 'credit': return 'bg-blue-50 text-blue-700 border-blue-200';
    case 'docs': return 'bg-purple-50 text-purple-700 border-purple-200';
    case 'decision': return 'bg-amber-50 text-amber-700 border-amber-200';
    case 'appointment': return 'bg-cyan-50 text-cyan-700 border-cyan-200';
    case 'report': return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    default: return 'bg-slate-50 text-slate-700 border-slate-200';
  }
}

export default function ActivityPage() {
  const router = useRouter();
  const [logs, setLogs] = useState<ActivityLogDto[]>([]);
  const [traffic, setTraffic] = useState<TrafficDto | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [period, setPeriod] = useState(14);
  const [category, setCategory] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedLog, setSelectedLog] = useState<ActivityLogDto | null>(null);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  const fetchData = useCallback(async (isRefresh = false) => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    if (isRefresh) setRefreshing(true);
    try {
      const [logsRes, trafficRes] = await Promise.allSettled([
        getActivityLogs({ page, per_page: 50 }),
        getTraffic(period),
      ]);
      if (logsRes.status === 'fulfilled') {
        setLogs(logsRes.value.logs);
        setTotalPages(logsRes.value.meta.last_page);
      } else if (logsRes.reason instanceof ApiError) {
        setError(logsRes.reason.message);
      }
      if (trafficRes.status === 'fulfilled') setTraffic(trafficRes.value);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [router, period, page]);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  // Filter logs
  const filteredLogs = logs.filter((log) => {
    if (category !== 'all' && categorize(log.action) !== category) return false;
    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      return (
        log.action.toLowerCase().includes(q) ||
        log.actor.name.toLowerCase().includes(q) ||
        (log.ip_address?.includes(q) ?? false)
      );
    }
    return true;
  });

  function exportCSV() {
    const header = 'Date,Action,Acteur,Type,IP,Application\n';
    const rows = filteredLogs.map((l) =>
      [
        new Date(l.created_at).toISOString(),
        l.action,
        l.actor.name,
        l.actor.type,
        l.ip_address ?? '',
        l.credit_application_id ?? '',
      ].join(',')
    ).join('\n');
    const blob = new Blob([header + rows], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `audit_bts_admin_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  if (loading) return <PageLoading />;

  return (
    <div className="px-4 sm:px-8 py-8 max-w-7xl mx-auto space-y-6">
      {/* ── Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-[#0C1825] tracking-tight flex items-center gap-2.5">
            <Activity className="size-6 text-[#C0272D]" />
            Journal d'Audit & Métriques
          </h1>
          <p className="text-sm text-[#3D5166] mt-0.5">Traces d'activité, événements de sécurité et analyses de trafic.</p>
        </div>

        <div className="flex items-center gap-3">
          <div className="flex items-center bg-white rounded-xl border border-[#E0E4E9] p-1 shadow-xs">
            {PERIOD_OPTIONS.map((opt) => (
              <button
                key={opt.value}
                type="button"
                onClick={() => setPeriod(opt.value)}
                className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-all ${
                  period === opt.value
                    ? 'bg-[#C0272D] text-white shadow-xs'
                    : 'text-[#3D5166] hover:bg-[#F4F6F8]'
                }`}
              >
                {opt.label}
              </button>
            ))}
          </div>

          <button
            type="button"
            onClick={() => fetchData(true)}
            disabled={refreshing}
            className="size-9 rounded-xl bg-white border border-[#E0E4E9] shadow-xs flex items-center justify-center text-[#3D5166] hover:text-[#C0272D] transition-all"
            title="Rafraîchir"
          >
            <RefreshCw className={`size-4 ${refreshing ? 'animate-spin' : ''}`} />
          </button>

          <button
            type="button"
            onClick={exportCSV}
            className="hidden sm:flex size-9 rounded-xl bg-white border border-[#E0E4E9] shadow-xs items-center justify-center text-[#3D5166] hover:text-[#C0272D] transition-all"
            title="Exporter CSV"
          >
            <Download className="size-4" />
          </button>
        </div>
      </div>

      <ErrorAlert message={error} />

      {/* ── Traffic KPIs ── */}
      {traffic && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <KpiMini label="Total événements" value={traffic.total_events} icon={Activity} color="text-[#C0272D]" bgColor="bg-[#FDF2F2]" />
          <KpiMini label="Clients actifs" value={traffic.active_customers} icon={Globe} color="text-blue-600" bgColor="bg-blue-50" />
          <KpiMini label="Staff actifs" value={traffic.active_staff} icon={Shield} color="text-emerald-600" bgColor="bg-emerald-50" />
          <KpiMini label="IPs uniques" value={traffic.unique_ips ?? 0} icon={Globe} color="text-purple-600" bgColor="bg-purple-50" />
        </div>
      )}

      {/* ── Category Filters ── */}
      <div className="flex items-center gap-2 overflow-x-auto pb-1">
        {CATEGORY_FILTERS.map((cat) => {
          const Icon = cat.icon;
          return (
            <button
              key={cat.id}
              type="button"
              onClick={() => setCategory(cat.id)}
              className={`flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-xl border transition-all whitespace-nowrap ${
                category === cat.id
                  ? 'bg-[#C0272D] text-white border-[#C0272D] shadow-xs'
                  : 'bg-white text-[#3D5166] border-[#E0E4E9] hover:bg-[#F4F6F8]'
              }`}
            >
              <Icon className="size-3.5" />
              {cat.label}
            </button>
          );
        })}
      </div>

      {/* ── Search ── */}
      <div className="relative">
        <Search className="absolute left-4 top-1/2 -translate-y-1/2 size-4 text-[#3D5166]" />
        <input
          type="text"
          placeholder="Rechercher par action, acteur, adresse IP…"
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          className="w-full pl-11 pr-4 py-3 text-sm bg-white rounded-xl border border-[#E0E4E9] shadow-xs placeholder:text-[#3D5166]/50 focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]/20"
        />
      </div>

      {/* ── Logs Table ── */}
      <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs overflow-hidden">
        <div className="hidden sm:grid sm:grid-cols-12 gap-4 px-6 py-3 bg-[#F4F6F8] border-b border-[#E0E4E9] text-[10px] font-bold uppercase tracking-wider text-[#3D5166]">
          <div className="col-span-2">Horodatage</div>
          <div className="col-span-3">Action</div>
          <div className="col-span-2">Acteur</div>
          <div className="col-span-2">IP / Agent</div>
          <div className="col-span-2">Dossier</div>
          <div className="col-span-1"></div>
        </div>

        {filteredLogs.length === 0 ? (
          <div className="py-12 text-center">
            <Activity className="size-8 text-[#E0E4E9] mx-auto mb-3" />
            <p className="text-sm text-[#3D5166]">Aucun événement trouvé.</p>
          </div>
        ) : (
          <div className="divide-y divide-[#F4F6F8]">
            {filteredLogs.map((log) => (
              <div
                key={log.id}
                className="grid grid-cols-1 sm:grid-cols-12 gap-2 sm:gap-4 px-6 py-3 items-center hover:bg-[#FDF2F2]/20 transition-colors cursor-pointer"
                onClick={() => setSelectedLog(log)}
              >
                <div className="sm:col-span-2 text-[11px] text-[#3D5166] font-mono">
                  {new Date(log.created_at).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                </div>
                <div className="sm:col-span-3">
                  <span className={`inline-flex items-center text-[10px] font-bold px-2 py-0.5 rounded-lg border ${actionColor(log.action)}`}>
                    {log.action.replace(/_/g, ' ')}
                  </span>
                </div>
                <div className="sm:col-span-2">
                  <p className="text-xs font-semibold text-[#0C1825] truncate">{log.actor.name}</p>
                  <p className="text-[10px] text-[#3D5166]">{log.actor.type === 'staff' ? (log.actor.role === 'admin' ? 'Admin' : 'Conseiller') : log.actor.type === 'customer' ? 'Client' : 'Système'}</p>
                </div>
                <div className="sm:col-span-2 text-[10px] text-[#3D5166] font-mono truncate">
                  {log.ip_address ?? '—'}
                </div>
                <div className="sm:col-span-2">
                  {log.credit_application_id ? (
                    <span className="text-[11px] font-mono text-[#C0272D]">#{log.credit_application_id}</span>
                  ) : (
                    <span className="text-[10px] text-[#3D5166]">—</span>
                  )}
                </div>
                <div className="sm:col-span-1 flex justify-end">
                  <Eye className="size-3.5 text-[#E0E4E9]" />
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* ── Log Detail Modal ── */}
      {selectedLog && (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
          <div className="absolute inset-0 bg-black/30 backdrop-blur-sm" onClick={() => setSelectedLog(null)} />
          <div className="relative bg-white rounded-2xl border border-[#E0E4E9] shadow-2xl max-w-lg w-full mx-4 max-h-[80vh] overflow-y-auto">
            <div className="flex items-center justify-between px-6 py-4 border-b border-[#E0E4E9]">
              <h3 className="text-sm font-bold text-[#0C1825]">Détail de l'événement</h3>
              <button type="button" onClick={() => setSelectedLog(null)} className="size-7 rounded-lg flex items-center justify-center text-[#3D5166] hover:bg-[#F4F6F8]">
                <X className="size-4" />
              </button>
            </div>
            <div className="px-6 py-5 space-y-4">
              <ModalRow label="Action" value={selectedLog.action} />
              <ModalRow label="Horodatage" value={new Date(selectedLog.created_at).toLocaleString('fr-FR')} />
              <ModalRow label="Acteur" value={`${selectedLog.actor.name} (${selectedLog.actor.type})`} />
              {selectedLog.ip_address && <ModalRow label="Adresse IP" value={selectedLog.ip_address} />}
              {selectedLog.user_agent && <ModalRow label="User Agent" value={selectedLog.user_agent} />}
              {selectedLog.credit_application_id && <ModalRow label="Dossier" value={`#${selectedLog.credit_application_id}`} />}
              {selectedLog.previous_state && (
                <div>
                  <p className="text-[10px] font-bold uppercase tracking-wider text-[#3D5166] mb-1">État précédent</p>
                  <pre className="text-[11px] bg-[#F4F6F8] rounded-xl p-3 overflow-x-auto font-mono text-[#0C1825] max-h-32">
                    {JSON.stringify(selectedLog.previous_state, null, 2)}
                  </pre>
                </div>
              )}
              {selectedLog.new_state && (
                <div>
                  <p className="text-[10px] font-bold uppercase tracking-wider text-[#3D5166] mb-1">Nouvel état</p>
                  <pre className="text-[11px] bg-emerald-50 rounded-xl p-3 overflow-x-auto font-mono text-emerald-900 max-h-32">
                    {JSON.stringify(selectedLog.new_state, null, 2)}
                  </pre>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function KpiMini({ label, value, icon: Icon, color, bgColor }: {
  label: string;
  value: number;
  icon: React.ComponentType<{ className?: string }>;
  color: string;
  bgColor: string;
}) {
  return (
    <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs p-4 flex items-center gap-3">
      <div className={`size-9 rounded-xl ${bgColor} ${color} flex items-center justify-center shrink-0`}>
        <Icon className="size-[18px]" />
      </div>
      <div>
        <p className="text-lg font-bold text-[#0C1825]">{value.toLocaleString('fr-FR')}</p>
        <p className="text-[10px] text-[#3D5166] font-semibold uppercase tracking-wider">{label}</p>
      </div>
    </div>
  );
}

function ModalRow({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-[10px] font-bold uppercase tracking-wider text-[#3D5166]">{label}</p>
      <p className="text-sm text-[#0C1825] font-medium mt-0.5 break-all">{value}</p>
    </div>
  );
}
