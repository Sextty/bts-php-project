'use client';

import { useCallback, useEffect, useState, useMemo } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  Activity,
  ShieldCheck,
  Filter,
  ChevronLeft,
  ChevronRight,
  BarChart3,
  Users,
  Globe,
  Shield,
  Search,
  RefreshCw,
  Download,
  Eye,
  FileText,
  CheckCircle2,
  XCircle,
  AlertTriangle,
  MessageSquare,
  Calendar,
  Layers,
  ArrowUpRight,
  Sparkles,
  Server,
  UserCheck,
  Laptop,
  MapPin,
} from 'lucide-react';
import { StaffHeader } from '@/components/staff-header';
import { ErrorAlert } from '@/components/error-alert';
import { TrendChart, BarList } from '@/components/charts/trend-chart';
import { InlineLoading, PageLoading } from '@/components/page-loading';
import {
  getActivityLogs,
  getTraffic,
  type ActivityLogDto,
  type TrafficDto,
} from '@/lib/api/staff-insights';
import { ApiError } from '@/lib/api/client';
import { getStaffRole, getStaffToken } from '@/lib/auth/staff-token';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

function shortDate(iso: string): string {
  const d = new Date(iso);
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function formatExactDate(iso: string): string {
  const d = new Date(iso);
  return d.toLocaleString('fr-FR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });
}

function formatRelativeTime(iso: string): string {
  const now = new Date();
  const d = new Date(iso);
  const diffMs = now.getTime() - d.getTime();
  const diffMin = Math.floor(diffMs / (1000 * 60));
  const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

  if (diffMin < 1) return 'À l\'instant';
  if (diffMin < 60) return `Il y a ${diffMin} min`;
  if (diffHours < 24) return `Il y a ${diffHours}h`;
  if (diffDays === 1) return 'Hier';
  return `Il y a ${diffDays}j`;
}

function humanAction(action: string): string {
  return action
    .split('.')
    .map((part) => part.replace(/_/g, ' '))
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' · ');
}

type ActionCategory = 'all' | 'auth' | 'credit' | 'documents' | 'decisions' | 'appointments' | 'messages';

const CATEGORY_TABS: { id: ActionCategory; label: string; icon: React.ComponentType<{ className?: string }> }[] = [
  { id: 'all', label: 'Toutes les actions', icon: Layers },
  { id: 'auth', label: 'Sécurité & Auth', icon: ShieldCheck },
  { id: 'credit', label: 'Dossiers de Crédit', icon: FileText },
  { id: 'documents', label: 'Pièces & IA', icon: Sparkles },
  { id: 'decisions', label: 'Décisions Staff & Admin', icon: CheckCircle2 },
  { id: 'appointments', label: 'Rendez-vous', icon: Calendar },
  { id: 'messages', label: 'Discussion Conseiller', icon: MessageSquare },
];

function getActionCategory(action: string): ActionCategory {
  const a = action.toLowerCase();
  if (a.startsWith('auth.') || a.includes('login') || a.includes('logout') || a.includes('otp') || a.includes('password')) {
    return 'auth';
  }
  if (a.includes('approved') || a.includes('rejected') || a.includes('decide')) {
    return 'decisions';
  }
  if (a.startsWith('appointment.') || a.includes('appointment')) {
    return 'appointments';
  }
  if (a.startsWith('document') || a.includes('document') || a.includes('ai_')) {
    return 'documents';
  }
  if (a.startsWith('report.') || a.includes('message')) {
    return 'messages';
  }
  return 'credit';
}

function getActionBadgeStyle(action: string) {
  const a = action.toLowerCase();
  if (a.includes('approved') || a.includes('passed') || a.includes('confirmed') || a.includes('accepted')) {
    return {
      bg: 'bg-emerald-50 text-emerald-800 border-emerald-200',
      dot: 'bg-emerald-500',
      icon: CheckCircle2,
    };
  }
  if (a.includes('rejected') || a.includes('failed') || a.includes('deleted') || a.includes('cancelled')) {
    return {
      bg: 'bg-red-50 text-red-800 border-red-200',
      dot: 'bg-red-500',
      icon: XCircle,
    };
  }
  if (a.includes('locked') || a.includes('submitted') || a.includes('validation')) {
    return {
      bg: 'bg-amber-50 text-amber-800 border-amber-200',
      dot: 'bg-amber-500',
      icon: AlertTriangle,
    };
  }
  if (a.includes('appointment')) {
    return {
      bg: 'bg-purple-50 text-purple-800 border-purple-200',
      dot: 'bg-purple-500',
      icon: Calendar,
    };
  }
  if (a.includes('message') || a.includes('report')) {
    return {
      bg: 'bg-sky-50 text-sky-800 border-sky-200',
      dot: 'bg-sky-500',
      icon: MessageSquare,
    };
  }
  return {
    bg: 'bg-slate-50 text-slate-800 border-slate-200',
    dot: 'bg-slate-500',
    icon: Activity,
  };
}

export default function ActivityPage() {
  const router = useRouter();
  const role = getStaffRole() ?? 'staff';
  const isAdmin = role === 'admin';

  const [days, setDays] = useState<number>(14);
  const [traffic, setTraffic] = useState<TrafficDto | null>(null);
  const [logs, setLogs] = useState<ActivityLogDto[]>([]);
  const [actions, setActions] = useState<string[]>([]);
  const [selectedCategory, setSelectedCategory] = useState<ActionCategory>('all');
  const [actionFilter, setActionFilter] = useState<string>('all');
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [logsLoading, setLogsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedLog, setSelectedLog] = useState<ActivityLogDto | null>(null);

  const loadLogs = useCallback(async (nextPage: number, action: string) => {
    setLogsLoading(true);
    try {
      const data = await getActivityLogs({
        page: nextPage,
        action: action === 'all' ? undefined : action,
      });
      setLogs(data.logs);
      setActions(data.available_actions);
      setLastPage(data.meta.last_page);
      setTotal(data.meta.total);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Impossible de charger le journal d’audit.');
    } finally {
      setLogsLoading(false);
    }
  }, []);

  const loadTrafficData = useCallback(async (selectedDays: number) => {
    try {
      const t = await getTraffic(selectedDays);
      setTraffic(t);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Impossible de charger les métriques de trafic.');
    }
  }, []);

  useEffect(() => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    let active = true;
    queueMicrotask(() => {
      if (!active) return;
      setLoading(true);
      Promise.all([loadTrafficData(days), loadLogs(1, 'all')])
        .catch((err) => {
          if (active) setError(err instanceof ApiError ? err.message : 'Erreur de chargement.');
        })
        .finally(() => {
          if (active) setLoading(false);
        });
    });
    return () => {
      active = false;
    };
  }, [router, days, loadLogs, loadTrafficData]);

  async function handleRefresh() {
    setRefreshing(true);
    setError(null);
    try {
      await Promise.all([loadTrafficData(days), loadLogs(page, actionFilter)]);
    } finally {
      setRefreshing(false);
    }
  }

  function handleDaysChange(newDays: number) {
    setDays(newDays);
  }

  function handleCategoryChange(cat: ActionCategory) {
    setSelectedCategory(cat);
    setActionFilter('all');
    setPage(1);
    loadLogs(1, 'all');
  }

  function changeAction(value: string) {
    setActionFilter(value);
    setPage(1);
    loadLogs(1, value);
  }

  function goToPage(next: number) {
    setPage(next);
    loadLogs(next, actionFilter);
  }

  // Filter logs by search term and selected category tab
  const filteredLogs = useMemo(() => {
    return logs.filter((log) => {
      // Category tab match
      if (selectedCategory !== 'all') {
        const cat = getActionCategory(log.action);
        if (cat !== selectedCategory) return false;
      }

      // Search term match
      if (!searchTerm.trim()) return true;
      const term = searchTerm.toLowerCase();
      const actionMatch = log.action.toLowerCase().includes(term);
      const actorMatch = log.actor.name.toLowerCase().includes(term);
      const roleMatch = (log.actor.role ?? log.actor.type).toLowerCase().includes(term);
      const idMatch = log.credit_application_id ? String(log.credit_application_id).includes(term) : false;
      const ipMatch = log.ip_address ? log.ip_address.toLowerCase().includes(term) : false;

      return actionMatch || actorMatch || roleMatch || idMatch || ipMatch;
    });
  }, [logs, selectedCategory, searchTerm]);

  function exportCsv() {
    if (filteredLogs.length === 0) return;

    const headers = ['ID', 'Date', 'Acteur', 'Type Acteur', 'Action', 'Dossier ID', ...(isAdmin ? ['IP Address', 'User Agent'] : [])];
    const rows = filteredLogs.map((log) => [
      log.id,
      log.created_at,
      `"${log.actor.name.replace(/"/g, '""')}"`,
      log.actor.role ?? log.actor.type,
      log.action,
      log.credit_application_id ?? '',
      ...(isAdmin ? [log.ip_address ?? '', `"${(log.user_agent ?? '').replace(/"/g, '""')}"`] : []),
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((e) => e.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `journal_audit_bts_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  if (loading) return <PageLoading />;

  return (
    <div className="staff-page text-[#1E2D3D]">
      <StaffHeader role={role} />

      <main id="main" className="staff-main space-y-6">
        {/* ── 1. Page Hero Header ── */}
        <section className="staff-page-hero flex flex-col justify-between gap-4 md:flex-row md:items-end">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <p className="overline">Traçabilité & Sécurité Bancaire BCT</p>
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                <span className="size-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                Piste d&apos;Audit Immuable
              </span>
            </div>
            <h1 className="font-display text-3xl font-light text-[#0C1825]">
              Journal d&apos;Audit & Statistiques d&apos;Activité
            </h1>
            <p className="text-xs text-[#3D5166] max-w-2xl">
              {isAdmin
                ? 'Registre centralisé des transactions, empreintes IP, événements de gouvernance et décisions de crédit.'
                : 'Suivi opérationnel des actions, vérifications de pièces justificatives et instructions de dossiers.'}
            </p>
          </div>

          {/* Action Toolbar */}
          <div className="flex flex-wrap items-center gap-2">
            {/* Time Window Switcher */}
            <div className="bg-white rounded-lg border border-[#E0E4E9] p-1 flex items-center shadow-xs">
              {[7, 14, 30, 90].map((d) => (
                <button
                  key={d}
                  type="button"
                  onClick={() => handleDaysChange(d)}
                  className={`text-xs font-semibold px-2.5 py-1 rounded transition-colors ${
                    days === d
                      ? 'bg-[#C0272D] text-white shadow-xs'
                      : 'text-[#3D5166] hover:text-[#0C1825] hover:bg-[#F4F6F8]'
                  }`}
                >
                  {d}j
                </button>
              ))}
            </div>

            <button
              type="button"
              onClick={handleRefresh}
              disabled={refreshing}
              className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#0C1825] bg-white px-3 py-2 rounded-lg border border-[#E0E4E9] hover:bg-[#F4F6F8] shadow-xs transition-colors disabled:opacity-60"
              title="Actualiser les données"
            >
              <RefreshCw className={`size-3.5 ${refreshing ? 'animate-spin text-[#C0272D]' : ''}`} />
              <span>Actualiser</span>
            </button>

            <button
              type="button"
              onClick={exportCsv}
              disabled={filteredLogs.length === 0}
              className="inline-flex items-center gap-1.5 text-xs font-semibold text-white bg-[#0C1825] px-3.5 py-2 rounded-lg hover:bg-[#1E2D3D] shadow-xs transition-colors disabled:opacity-50"
              title="Exporter les événements au format CSV"
            >
              <Download className="size-3.5" />
              <span>Exporter CSV</span>
            </button>
          </div>
        </section>

        <ErrorAlert message={error} />

        {!isAdmin && (
          <div className="figma-card p-4 bg-gradient-to-r from-white to-[#FDF2F2] border-l-4 border-l-[#C0272D] flex items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <div className="size-8 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center shrink-0">
                <ShieldCheck className="size-4" />
              </div>
              <div className="text-xs space-y-0.5">
                <span className="font-bold text-[#0C1825]">Périmètre Conseiller d&apos;Agence :</span>
                <p className="text-[#3D5166]">
                  Vous consultez les événements de votre file d&apos;instruction. Les logs de gouvernance globale et les adresses IP sont réservés à l&apos;Administration Centrale.
                </p>
              </div>
            </div>
            <span className="badge badge-neutral text-[10px] shrink-0">Isolation Agence</span>
          </div>
        )}

        {/* ── 2. Metric KPI Cards ── */}
        {traffic && (
          <div className="space-y-6">
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              <MetricCard
                label={`Événements (${traffic.days} jours)`}
                value={traffic.total_events.toLocaleString('fr-FR')}
                subtext="Total des opérations tracées"
                icon={BarChart3}
                accent="red"
              />
              <MetricCard
                label="Demandeurs Actifs"
                value={traffic.active_customers.toLocaleString('fr-FR')}
                subtext="Clients ayant interagi"
                icon={Users}
                accent="blue"
              />
              <MetricCard
                label="Conseillers & Admins"
                value={traffic.active_staff.toLocaleString('fr-FR')}
                subtext="Agents ayant instruit"
                icon={Shield}
                accent="purple"
              />
              <MetricCard
                label="Adresses IP Uniques"
                value={traffic.unique_ips === null ? 'Réservé Admin' : traffic.unique_ips.toLocaleString('fr-FR')}
                subtext={isAdmin ? 'Empreintes réseau distinctes' : 'Accès confidentiel'}
                icon={Globe}
                accent="emerald"
              />
            </div>

            {/* ── 3. Interactive Charts Grid ── */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              {/* Timeline Trend Chart */}
              <div className="figma-card p-6 bg-white space-y-4 lg:col-span-2 shadow-xs">
                <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-3">
                  <div>
                    <h3 className="text-sm font-semibold text-[#0C1825]">
                      Volume d&apos;Activité Quotidien ({traffic.days} derniers jours)
                    </h3>
                    <p className="text-[11px] text-[#3D5166]">
                      Distribution temporelle des requêtes et modifications de statut
                    </p>
                  </div>
                  <span className="text-[10px] font-mono font-bold text-[#C0272D] bg-[#FDF2F2] px-2 py-0.5 rounded border border-[#FECACA]">
                    {traffic.timeline.reduce((sum, t) => sum + t.events, 0)} événements
                  </span>
                </div>

                <div className="pt-2">
                  <TrendChart
                    height={220}
                    labels={traffic.timeline.map((t) => shortDate(t.date))}
                    series={[
                      {
                        key: 'events',
                        label: 'Événements',
                        colorVar: '--viz-series-1',
                        values: traffic.timeline.map((t) => t.events),
                      },
                    ]}
                  />
                </div>
              </div>

              {/* Top Actions Breakdown */}
              <div className="figma-card p-6 bg-white space-y-4 shadow-xs">
                <div className="border-b border-[#E0E4E9] pb-3">
                  <h3 className="text-sm font-semibold text-[#0C1825]">
                    Actions les plus fréquentes
                  </h3>
                  <p className="text-[11px] text-[#3D5166]">
                    Top des opérations enregistrées sur la période
                  </p>
                </div>

                <div className="pt-1">
                  <BarList
                    items={traffic.top_actions.map((a) => ({
                      label: humanAction(a.action),
                      value: a.count,
                      hint: a.action,
                    }))}
                    emptyLabel="Aucune activité enregistrée sur cette période."
                  />
                </div>
              </div>
            </div>
          </div>
        )}

        {/* ── 4. Audit Trail Table Section ── */}
        <div className="figma-card bg-white p-6 sm:p-8 space-y-6 shadow-xs">
          {/* Table Header & Controls */}
          <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-[#E0E4E9] pb-5">
            <div>
              <div className="flex items-center gap-2">
                <h3 className="font-display text-xl font-light text-[#0C1825]">
                  Piste d&apos;Audit des Opérations
                </h3>
                <span className="text-xs font-mono font-bold text-[#3D5166] bg-[#F4F6F8] px-2 py-0.5 rounded border border-[#E0E4E9]">
                  {total} au total
                </span>
              </div>
              <p className="text-xs text-[#3D5166] mt-0.5">
                Cliquez sur une ligne pour inspecter les détails complets et les changements d&apos;état.
              </p>
            </div>

            {/* Search & Action Filter Controls */}
            <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5">
              {/* Search Bar */}
              <div className="relative min-w-[240px]">
                <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 size-3.5 text-gray-400" />
                <input
                  type="text"
                  placeholder="Rechercher acteur, action, dossier..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full text-xs bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg pl-8 pr-3 py-2 text-[#0C1825] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]"
                />
                {searchTerm && (
                  <button
                    type="button"
                    onClick={() => setSearchTerm('')}
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[10px] text-gray-400 hover:text-gray-600"
                  >
                    Effacer
                  </button>
                )}
              </div>

              {/* Action Dropdown */}
              <div className="flex items-center gap-1.5">
                <Filter className="size-3.5 text-[#3D5166] shrink-0" />
                <select
                  value={actionFilter}
                  onChange={(e) => changeAction(e.target.value)}
                  className="text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3 py-2 text-[#0C1825] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]"
                >
                  <option value="all">Toutes les actions API</option>
                  {actions.map((a) => (
                    <option key={a} value={a}>
                      {humanAction(a)}
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </div>

          {/* Category Tabs */}
          <div className="flex items-center gap-1.5 overflow-x-auto pb-1 border-b border-[#E0E4E9]">
            {CATEGORY_TABS.map((tab) => {
              const Icon = tab.icon;
              const isSelected = selectedCategory === tab.id;
              return (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => handleCategoryChange(tab.id)}
                  className={`inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg whitespace-nowrap transition-colors ${
                    isSelected
                      ? 'bg-[#C0272D] text-white shadow-xs'
                      : 'bg-[#F4F6F8] text-[#3D5166] hover:bg-gray-200 hover:text-[#0C1825]'
                  }`}
                >
                  <Icon className="size-3.5" />
                  <span>{tab.label}</span>
                </button>
              );
            })}
          </div>

          {/* Table Container */}
          {logsLoading ? (
            <div className="py-16 text-center space-y-2">
              <InlineLoading />
              <p className="text-xs text-[#3D5166]">Chargement de la piste d&apos;audit…</p>
            </div>
          ) : filteredLogs.length === 0 ? (
            <div className="py-16 text-center space-y-3 bg-[#F4F6F8]/50 rounded-xl border border-dashed border-[#E0E4E9]">
              <div className="size-12 rounded-full bg-white border border-[#E0E4E9] flex items-center justify-center mx-auto text-gray-400 shadow-xs">
                <Search className="size-5" />
              </div>
              <div className="space-y-1">
                <p className="text-xs font-bold text-[#0C1825]">Aucun événement trouvé</p>
                <p className="text-[11px] text-[#3D5166]">
                  Aucun log ne correspond à vos filtres actuels (« {searchTerm || selectedCategory} »).
                </p>
              </div>
              {(searchTerm || selectedCategory !== 'all' || actionFilter !== 'all') && (
                <button
                  type="button"
                  onClick={() => {
                    setSearchTerm('');
                    setSelectedCategory('all');
                    changeAction('all');
                  }}
                  className="btn-outline min-h-9 px-3.5 py-1.5 text-xs"
                >
                  Réinitialiser tous les filtres
                </button>
              )}
            </div>
          ) : (
            <div className="space-y-4">
              <div className="overflow-x-auto rounded-xl border border-[#E0E4E9]">
                <table className="w-full text-left text-xs border-collapse">
                  <thead>
                    <tr className="bg-[#F4F6F8] border-b border-[#E0E4E9] text-[#3D5166] uppercase text-[10px] font-bold tracking-wider">
                      <th className="py-3 px-4">Date & Heure</th>
                      <th className="py-3 px-4">Acteur de l&apos;Opération</th>
                      <th className="py-3 px-4">Action Enregistrée</th>
                      <th className="py-3 px-4">Dossier</th>
                      {isAdmin && <th className="py-3 px-4">Adresse IP</th>}
                      <th className="py-3 px-4 text-right">Détails</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#E0E4E9] bg-white">
                    {filteredLogs.map((log) => {
                      const badge = getActionBadgeStyle(log.action);
                      const BadgeIcon = badge.icon;
                      return (
                        <tr
                          key={log.id}
                          onClick={() => setSelectedLog(log)}
                          className="hover:bg-[#FDF2F2]/40 cursor-pointer transition-colors group"
                        >
                          {/* Date & Time */}
                          <td className="py-3 px-4 whitespace-nowrap">
                            <div className="font-mono text-xs font-semibold text-[#0C1825]">
                              {formatExactDate(log.created_at)}
                            </div>
                            <span className="text-[10px] text-[#3D5166]">
                              {formatRelativeTime(log.created_at)}
                            </span>
                          </td>

                          {/* Actor */}
                          <td className="py-3 px-4">
                            <div className="flex items-center gap-2">
                              <div className="size-7 rounded-full bg-[#F4F6F8] border border-[#E0E4E9] flex items-center justify-center text-xs font-bold text-[#0C1825] shrink-0">
                                {log.actor.name.charAt(0).toUpperCase()}
                              </div>
                              <div className="min-w-0">
                                <p className="font-semibold text-[#0C1825] truncate">{log.actor.name}</p>
                                <span className="inline-block text-[9px] uppercase font-bold px-1.5 py-0.2 rounded bg-[#F4F6F8] text-[#3D5166] border border-[#E0E4E9]">
                                  {log.actor.role ?? log.actor.type}
                                </span>
                              </div>
                            </div>
                          </td>

                          {/* Action Badge */}
                          <td className="py-3 px-4">
                            <div className="inline-flex max-w-md items-center gap-1.5 truncate rounded-md border px-2.5 py-1 text-[11px] font-medium">
                              <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded border text-[11px] font-semibold ${badge.bg}`}>
                                <BadgeIcon className="size-3" />
                                <span>{humanAction(log.action)}</span>
                              </span>
                            </div>
                          </td>

                          {/* Application ID */}
                          <td className="py-3 px-4 whitespace-nowrap">
                            {log.credit_application_id ? (
                              <Link
                                href={`/dashboard/${log.credit_application_id}`}
                                onClick={(e) => e.stopPropagation()}
                                className="inline-flex items-center gap-1 font-mono text-xs font-bold text-[#C0272D] bg-[#FDF2F2] px-2 py-0.5 rounded border border-[#FECACA] hover:bg-[#C0272D] hover:text-white transition-colors"
                              >
                                <span>#{log.credit_application_id}</span>
                                <ArrowUpRight className="size-3" />
                              </Link>
                            ) : (
                              <span className="text-[#3D5166] font-mono text-xs">—</span>
                            )}
                          </td>

                          {/* IP Address (Admin Only) */}
                          {isAdmin && (
                            <td className="py-3 px-4 whitespace-nowrap">
                              <span className="font-mono text-xs text-[#3D5166] bg-[#F4F6F8] px-2 py-0.5 rounded border border-[#E0E4E9]">
                                {log.ip_address ?? '—'}
                              </span>
                            </td>
                          )}

                          {/* View Detail Action */}
                          <td className="py-3 px-4 text-right">
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                setSelectedLog(log);
                              }}
                              className="p-1.5 text-[#3D5166] group-hover:text-[#C0272D] rounded-lg group-hover:bg-[#FDF2F2] transition-colors"
                              title="Voir les détails complets"
                            >
                              <Eye className="size-4" />
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-4 border-t border-[#E0E4E9]">
                <p className="text-xs text-[#3D5166]">
                  Affichage de <span className="font-bold text-[#0C1825]">{filteredLogs.length}</span> événement{filteredLogs.length > 1 ? 's' : ''} sur{' '}
                  <span className="font-bold text-[#0C1825]">{total}</span>
                </p>

                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    disabled={page <= 1 || logsLoading}
                    onClick={() => goToPage(page - 1)}
                    className="btn-outline min-h-9 px-3 py-1.5 text-xs disabled:opacity-50"
                  >
                    <ChevronLeft className="size-3.5" />
                    <span>Précédent</span>
                  </button>

                  <span className="text-xs font-semibold text-[#0C1825] bg-[#F4F6F8] px-3 py-1.5 rounded-lg border border-[#E0E4E9]">
                    Page {page} / {lastPage || 1}
                  </span>

                  <button
                    type="button"
                    disabled={page >= lastPage || logsLoading}
                    onClick={() => goToPage(page + 1)}
                    className="btn-outline min-h-9 px-3 py-1.5 text-xs disabled:opacity-50"
                  >
                    <span>Suivant</span>
                    <ChevronRight className="size-3.5" />
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      </main>

      {/* ── 5. Log Inspector Modal Dialog ── */}
      <Dialog open={selectedLog !== null} onOpenChange={(open) => !open && setSelectedLog(null)}>
        <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
          {selectedLog && (
            <div className="space-y-5">
              <DialogHeader>
                <div className="flex items-center gap-2">
                  <div className="size-8 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                    <Activity className="size-4" />
                  </div>
                  <div>
                    <DialogTitle className="text-base font-semibold text-[#0C1825]">
                      Détail de l&apos;Événement d&apos;Audit #{selectedLog.id}
                    </DialogTitle>
                    <DialogDescription className="text-xs text-[#3D5166]">
                      Enregistré le {formatExactDate(selectedLog.created_at)} ({formatRelativeTime(selectedLog.created_at)})
                    </DialogDescription>
                  </div>
                </div>
              </DialogHeader>

              {/* Meta Grid */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                <div className="p-3 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] space-y-1">
                  <span className="text-[10px] font-bold uppercase text-[#3D5166] flex items-center gap-1">
                    <UserCheck className="size-3 text-[#C0272D]" /> Acteur
                  </span>
                  <p className="font-bold text-[#0C1825]">{selectedLog.actor.name}</p>
                  <p className="text-[11px] text-[#3D5166] uppercase">
                    Rôle : {selectedLog.actor.role ?? selectedLog.actor.type}
                  </p>
                </div>

                <div className="p-3 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] space-y-1">
                  <span className="text-[10px] font-bold uppercase text-[#3D5166] flex items-center gap-1">
                    <FileText className="size-3 text-[#C0272D]" /> Dossier Associé
                  </span>
                  {selectedLog.credit_application_id ? (
                    <div className="flex items-center justify-between">
                      <p className="font-bold font-mono text-[#C0272D]">
                        Dossier #{selectedLog.credit_application_id}
                      </p>
                      <Link
                        href={`/dashboard/${selectedLog.credit_application_id}`}
                        className="inline-flex items-center gap-1 text-xs font-semibold text-[#C0272D] hover:underline"
                      >
                        Ouvrir <ArrowUpRight className="size-3" />
                      </Link>
                    </div>
                  ) : (
                    <p className="text-[#3D5166]">Aucun dossier associé (action système/compte)</p>
                  )}
                </div>

                {selectedLog.device && (
                  <div className="p-3.5 bg-white rounded-xl border border-[#E0E4E9] space-y-3 sm:col-span-2 shadow-xs">
                    <span className="text-[11px] font-bold uppercase text-[#0C1825] flex items-center gap-1.5">
                      <Laptop className="size-3.5 text-[#C0272D]" /> Système, Matériel & Cartes Réseau
                    </span>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs">
                      <div>
                        <span className="text-[10px] text-gray-500 font-semibold uppercase">Système (OS) :</span>
                        <p className="font-bold text-[#0C1825]">{selectedLog.device.os}</p>
                      </div>
                      <div>
                        <span className="text-[10px] text-gray-500 font-semibold uppercase">Modèle Machine :</span>
                        <p className="font-bold text-[#0C1825]">{selectedLog.device.computer_model || selectedLog.device.device_model}</p>
                      </div>
                      {selectedLog.device.cpu && (
                        <div>
                          <span className="text-[10px] text-gray-500 font-semibold uppercase">Processeur (CPU) :</span>
                          <p className="font-semibold text-[#0C1825] text-[11px]">{selectedLog.device.cpu}</p>
                        </div>
                      )}
                      {selectedLog.device.ram && (
                        <div>
                          <span className="text-[10px] text-gray-500 font-semibold uppercase">Mémoire (RAM) :</span>
                          <p className="font-semibold text-[#0C1825]">{selectedLog.device.ram}</p>
                        </div>
                      )}
                      <div>
                        <span className="text-[10px] text-gray-500 font-semibold uppercase">Localisation :</span>
                        <p className="font-semibold text-emerald-800 flex items-center gap-1">
                          <MapPin className="size-3 text-red-500 shrink-0" />
                          <span>{selectedLog.device.location}</span>
                        </p>
                      </div>
                      <div>
                        <span className="text-[10px] text-gray-500 font-semibold uppercase">Adresse MAC Active :</span>
                        <p className="font-mono font-bold text-indigo-700 bg-indigo-50 px-1.5 py-0.5 rounded border border-indigo-100 inline-block">
                          {selectedLog.device.mac_address}
                        </p>
                      </div>
                    </div>

                    {selectedLog.device.network_adapters && selectedLog.device.network_adapters.length > 0 && (
                      <div className="pt-2 border-t border-[#E0E4E9] space-y-1.5">
                        <span className="text-[10px] font-bold uppercase text-gray-500">Cartes Réseau :</span>
                        <div className="space-y-1">
                          {selectedLog.device.network_adapters.map((ad, idx) => (
                            <div key={idx} className="flex items-center justify-between text-[11px] bg-gray-50 p-2 rounded border border-gray-200">
                              <span className="font-medium text-[#0C1825] truncate">{ad.name}</span>
                              <span className="font-mono font-bold text-indigo-700">{ad.mac_address}</span>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                )}

                {isAdmin && selectedLog.ip_address && (
                  <div className="p-3 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] space-y-1">
                    <span className="text-[10px] font-bold uppercase text-[#3D5166] flex items-center gap-1">
                      <Globe className="size-3 text-[#C0272D]" /> Adresse IP Client
                    </span>
                    <p className="font-bold font-mono text-[#0C1825]">{selectedLog.ip_address}</p>
                  </div>
                )}

                {isAdmin && selectedLog.user_agent && (
                  <div className="p-3 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] space-y-1 sm:col-span-2">
                    <span className="text-[10px] font-bold uppercase text-[#3D5166] flex items-center gap-1">
                      <Server className="size-3 text-[#C0272D]" /> Empreinte User Agent
                    </span>
                    <p className="font-mono text-[11px] text-[#3D5166] break-all">
                      {selectedLog.user_agent}
                    </p>
                  </div>
                )}
              </div>

              {/* Action Name Full */}
              <div className="p-3.5 bg-slate-900 text-white rounded-xl space-y-1 font-mono text-xs">
                <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                  Action Système Exécutée :
                </span>
                <p className="font-bold text-amber-400 break-all">{selectedLog.action}</p>
              </div>

              {/* State Changes Diff (Admin only or if available) */}
              {(selectedLog.previous_state || selectedLog.new_state) && (
                <div className="space-y-3">
                  <h4 className="text-xs font-bold uppercase tracking-wider text-[#0C1825] flex items-center gap-1.5">
                    <Layers className="size-3.5 text-[#C0272D]" />
                    Modifications d&apos;État (State Mutation)
                  </h4>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    {selectedLog.previous_state && (
                      <div className="p-3 bg-red-50/60 border border-red-200 rounded-xl space-y-2">
                        <span className="text-[10px] font-bold uppercase text-red-700">
                          État Précédent (Previous) :
                        </span>
                        <pre className="font-mono text-[10px] text-red-900 bg-white p-2.5 rounded border border-red-100 overflow-x-auto">
                          {JSON.stringify(selectedLog.previous_state, null, 2)}
                        </pre>
                      </div>
                    )}

                    {selectedLog.new_state && (
                      <div className="p-3 bg-emerald-50/60 border border-emerald-200 rounded-xl space-y-2">
                        <span className="text-[10px] font-bold uppercase text-emerald-700">
                          Nouvel État (New State) :
                        </span>
                        <pre className="font-mono text-[10px] text-emerald-900 bg-white p-2.5 rounded border border-emerald-100 overflow-x-auto">
                          {JSON.stringify(selectedLog.new_state, null, 2)}
                        </pre>
                      </div>
                    )}
                  </div>
                </div>
              )}
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}

function MetricCard({
  label,
  value,
  subtext,
  icon: Icon,
  accent,
}: {
  label: string;
  value: number | string;
  subtext?: string;
  icon: React.ComponentType<{ className?: string }>;
  accent: 'red' | 'blue' | 'purple' | 'emerald';
}) {
  const accentColors = {
    red: { bg: 'bg-[#FDF2F2]', text: 'text-[#C0272D]', bar: 'bg-[#C0272D]' },
    blue: { bg: 'bg-sky-50', text: 'text-sky-700', bar: 'bg-sky-600' },
    purple: { bg: 'bg-purple-50', text: 'text-purple-700', bar: 'bg-purple-600' },
    emerald: { bg: 'bg-emerald-50', text: 'text-emerald-700', bar: 'bg-emerald-600' },
  }[accent];

  return (
    <div className="figma-card p-5 bg-white space-y-3 relative overflow-hidden shadow-xs hover:border-[#C0272D]/40 transition-colors">
      <div className={`absolute top-0 left-0 right-0 h-1 ${accentColors.bar}`}></div>
      <div className="flex items-center justify-between text-[#3D5166]">
        <span className="text-[10px] font-bold uppercase tracking-wider">{label}</span>
        <div className={`size-8 rounded-lg ${accentColors.bg} ${accentColors.text} flex items-center justify-center`}>
          <Icon className="size-4" />
        </div>
      </div>
      <div>
        <p className="text-3xl font-display font-light text-[#0C1825]">{value}</p>
        {subtext && <p className="text-[10px] text-[#3D5166] mt-0.5">{subtext}</p>}
      </div>
    </div>
  );
}
