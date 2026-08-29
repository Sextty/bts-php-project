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
  X,
  Laptop,
  Smartphone,
  MapPin,
  Cpu,
  Monitor,
  Network,
  Wifi,
  Radio,
  Layers,
  Terminal,
  ShieldAlert,
} from 'lucide-react';
import { ErrorAlert } from '@/components/error-alert';
import { PageLoading } from '@/components/page-loading';
import {
  getActivityLogs,
  getTraffic,
  type ActivityLogDto,
  type TrafficDto,
  type DeviceDetailsDto,
} from '@/lib/api/staff-insights';
import {
  getOsqueryPresets,
  type OsqueryPresetCategoryDto,
} from '@/lib/api/osquery';
import { OsqueryTerminal } from '@/components/osquery/osquery-terminal';
import { OsqueryAuditPacks } from '@/components/osquery/osquery-audit-packs';
import { ApiError } from '@/lib/api/client';
import { getStaffToken } from '@/lib/auth/staff-token';
import { cn } from '@/lib/utils';
import { escapeCsvCell, saveBlob } from '@/lib/download';

const PERIOD_OPTIONS = [
  { value: 7, label: '7j' },
  { value: 14, label: '14j' },
  { value: 30, label: '30j' },
  { value: 90, label: '90j' },
] as const;

const CATEGORY_FILTERS = [
  { id: 'all', label: 'Toutes les Actions', icon: Activity },
  { id: 'auth', label: 'Sécurité & Auth', icon: Shield },
  { id: 'credit', label: 'Dossiers de Crédit', icon: FolderOpen },
  { id: 'docs', label: 'Pièces & IA', icon: FileCheck },
  { id: 'decision', label: 'Décisions', icon: Gavel },
  { id: 'appointment', label: 'Rendez-vous', icon: Calendar },
  { id: 'report', label: 'Discussions & Signalements', icon: MessageSquare },
] as const;

function categorize(action: string): string {
  if (
    action.includes('login') ||
    action.includes('logout') ||
    action.includes('otp') ||
    action.includes('register') ||
    action.includes('password') ||
    action.includes('google') ||
    action.includes('ban') ||
    action.includes('osquery')
  )
    return 'auth';
  if (action.includes('document') || action.includes('verification') || action.includes('ai_')) return 'docs';
  if (action.includes('approve') || action.includes('reject') || action.includes('cancel') || action.includes('decision'))
    return 'decision';
  if (action.includes('appointment') || action.includes('rdv')) return 'appointment';
  if (action.includes('report') || action.includes('message') || action.includes('chat')) return 'report';
  if (
    action.includes('credit') ||
    action.includes('application') ||
    action.includes('validation') ||
    action.includes('submit') ||
    action.includes('step')
  )
    return 'credit';
  return 'credit';
}

function actionColor(action: string): string {
  const cat = categorize(action);
  switch (cat) {
    case 'auth':
      return 'bg-red-50 text-red-700 border-red-200';
    case 'credit':
      return 'bg-blue-50 text-blue-700 border-blue-200';
    case 'docs':
      return 'bg-purple-50 text-purple-700 border-purple-200';
    case 'decision':
      return 'bg-amber-50 text-amber-700 border-amber-200';
    case 'appointment':
      return 'bg-cyan-50 text-cyan-700 border-cyan-200';
    case 'report':
      return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    default:
      return 'bg-slate-50 text-slate-700 border-slate-200';
  }
}

export default function ActivityPage() {
  const router = useRouter();
  const [activeTab, setActiveTab] = useState<'events' | 'osquery' | 'packs'>('events');
  const [selectedOsqueryQuery, setSelectedOsqueryQuery] = useState<string>('SELECT pid, port, protocol, address, process_name, state FROM listening_ports WHERE port != 0 ORDER BY port ASC;');
  const [presets, setPresets] = useState<OsqueryPresetCategoryDto[]>([]);

  const [logs, setLogs] = useState<ActivityLogDto[]>([]);
  const [traffic, setTraffic] = useState<TrafficDto | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [period, setPeriod] = useState(14);
  const [category, setCategory] = useState('all');
  const [osFilter, setOsFilter] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedLog, setSelectedLog] = useState<ActivityLogDto | null>(null);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  const fetchData = useCallback(
    async (isRefresh = false) => {
      if (!getStaffToken()) {
        router.replace('/login');
        return;
      }
      if (isRefresh) setRefreshing(true);
      setError(null);
      try {
        const [logsRes, trafficRes, presetsRes] = await Promise.allSettled([
          getActivityLogs({ page, per_page: 50 }),
          getTraffic(period),
          getOsqueryPresets(),
        ]);
        if (logsRes.status === 'fulfilled') {
          setLogs(logsRes.value.logs);
          setTotalPages(logsRes.value.meta.last_page);
        } else if (logsRes.reason instanceof ApiError) {
          setError(logsRes.reason.message);
        }
        if (trafficRes.status === 'fulfilled') setTraffic(trafficRes.value);
        else setError((current) => current ?? 'Les métriques réseau sont temporairement indisponibles.');
        if (presetsRes.status === 'fulfilled') setPresets(presetsRes.value);
        else setError((current) => current ?? 'Les requêtes Osquery prédéfinies sont temporairement indisponibles.');
      } finally {
        setLoading(false);
        setRefreshing(false);
      }
    },
    [router, period, page]
  );

  useEffect(() => {
    queueMicrotask(() => void fetchData());
  }, [fetchData]);

  // Filter logs with search, category, and OS
  const filteredLogs = logs.filter((log) => {
    if (category !== 'all' && categorize(log.action) !== category) return false;
    if (osFilter !== 'all' && log.device?.os_family !== osFilter) return false;

    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      const matchAction = log.action.toLowerCase().includes(q);
      const matchActor = log.actor.name.toLowerCase().includes(q);
      const matchIp = log.ip_address?.toLowerCase().includes(q) ?? false;
      const matchOs = log.device?.os?.toLowerCase().includes(q) ?? false;
      const matchModel = log.device?.device_model?.toLowerCase().includes(q) ?? false;
      const matchLocation = log.device?.location?.toLowerCase().includes(q) ?? false;
      const matchMac = log.device?.mac_address?.toLowerCase().includes(q) ?? false;
      const matchApp = log.credit_application_id ? String(log.credit_application_id).includes(q) : false;
      const matchAdapters = log.device?.network_adapters?.some(
        (a) =>
          a.name.toLowerCase().includes(q) ||
          a.description.toLowerCase().includes(q) ||
          a.mac_address.toLowerCase().includes(q)
      ) ?? false;

      return (
        matchAction ||
        matchActor ||
        matchIp ||
        matchOs ||
        matchModel ||
        matchLocation ||
        matchMac ||
        matchApp ||
        matchAdapters
      );
    }
    return true;
  });

  function exportCSV() {
    const header =
      'Date,Action,Acteur,Type,Système (OS),Modèle Machine,Processeur,RAM,GPU,Carte Réseau Active,Adresse MAC,Adresse IP,Localisation,Dossier\n';
    const rows = filteredLogs
      .map((l) =>
        [
          new Date(l.created_at).toISOString(),
          l.action,
          l.actor.name,
          l.actor.type,
          l.device?.os,
          l.device?.computer_model ?? l.device?.device_model,
          l.device?.cpu,
          l.device?.ram,
          l.device?.gpu,
          l.device?.active_network_adapter,
          l.device?.mac_address,
          l.ip_address,
          l.device?.location,
          l.credit_application_id,
        ].map(escapeCsvCell).join(',')
      )
      .join('\n');
    const blob = new Blob([header + rows], { type: 'text/csv;charset=utf-8;' });
    saveBlob(blob, `journal_audit_bts_admin_${new Date().toISOString().slice(0, 10)}.csv`);
  }

  const renderOsBadge = (device?: DeviceDetailsDto | null) => {
    if (!device) return <span className="text-gray-400 text-[10px]">—</span>;

    const family = device.os_family;
    if (family === 'windows') {
      const isWin11 = device.os.includes('11');
      return (
        <span
          className={cn(
            'inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold border',
            isWin11
              ? 'bg-blue-50 text-blue-700 border-blue-200'
              : 'bg-indigo-50 text-indigo-700 border-indigo-200'
          )}
        >
          <Monitor className="size-3 text-blue-600 shrink-0" />
          <span>{device.os_short || device.os}</span>
        </span>
      );
    }
    if (family === 'android') {
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-50 text-emerald-800 border border-emerald-200">
          <Smartphone className="size-3 text-emerald-600 shrink-0" />
          <span>{device.os_short || device.os}</span>
        </span>
      );
    }
    if (family === 'ios') {
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 text-slate-800 border border-slate-300">
          <Smartphone className="size-3 text-slate-700 shrink-0" />
          <span>{device.os_short || device.os}</span>
        </span>
      );
    }
    if (family === 'macos') {
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-200">
          <Laptop className="size-3 text-purple-600 shrink-0" />
          <span>{device.os_short || device.os}</span>
        </span>
      );
    }
    if (family === 'linux') {
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
          <Cpu className="size-3 text-amber-600 shrink-0" />
          <span>{device.os_short || device.os}</span>
        </span>
      );
    }

    return (
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-medium bg-gray-100 text-gray-700">
        <Monitor className="size-3 text-gray-500 shrink-0" />
        <span>{device.os || 'Inconnu'}</span>
      </span>
    );
  };

  const renderAdapterIcon = (type: string) => {
    switch (type) {
      case 'wifi':
        return <Wifi className="size-3.5 text-emerald-600" />;
      case 'ethernet':
        return <Network className="size-3.5 text-blue-600" />;
      case 'vmware':
      case 'hyperv':
        return <Layers className="size-3.5 text-purple-600" />;
      case 'bluetooth':
        return <Radio className="size-3.5 text-indigo-600" />;
      default:
        return <Network className="size-3.5 text-gray-500" />;
    }
  };

  if (loading) return <PageLoading />;

  return (
    <div>
      <div className="admin-page">
        {/* ── Header ── */}
        <div className="admin-page-hero flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div className="flex items-center gap-2 mb-1">
              <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-[#FDF2F2] text-[#C0272D] border border-[#FECACA]">
                <Shield className="size-3" />
                Surveillance Matérielle, Audit & Osquery BTS Bank
              </span>
            </div>
            <h1 className="text-2xl sm:text-3xl font-bold text-[#0C1825] tracking-tight flex items-center gap-2.5">
              <Activity className="size-7 text-[#C0272D]" />
              Journal d’Audit, Matériel & Console Osquery
            </h1>
            <p className="text-xs sm:text-sm text-[#3D5166] mt-1">
              Inspection matérielle réelle, analyse des cartes réseau, journal d’audit de sécurité et terminal interactif SQL Osquery.
            </p>
          </div>

          <div className="flex items-center gap-3">
            {activeTab === 'events' && (
              <div className="flex items-center bg-white rounded-xl border border-[#E0E4E9] p-1 shadow-xs">
                {PERIOD_OPTIONS.map((opt) => (
                  <button
                    key={opt.value}
                    type="button"
                    onClick={() => setPeriod(opt.value)}
                    className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-all ${
                      period === opt.value ? 'bg-[#C0272D] text-white shadow-xs' : 'text-[#3D5166] hover:bg-[#F4F6F8]'
                    }`}
                  >
                    {opt.label}
                  </button>
                ))}
              </div>
            )}

            <button
              type="button"
              onClick={() => fetchData(true)}
              disabled={refreshing}
              className="size-9 rounded-xl bg-white border border-[#E0E4E9] shadow-xs flex items-center justify-center text-[#3D5166] hover:text-[#C0272D] transition-all"
              title="Rafraîchir"
            >
              <RefreshCw className={`size-4 ${refreshing ? 'animate-spin' : ''}`} />
            </button>

            {activeTab === 'events' && (
              <button
                type="button"
                onClick={exportCSV}
                className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-white border border-[#E0E4E9] shadow-xs text-xs font-semibold text-[#3D5166] hover:text-[#C0272D] hover:border-[#C0272D]/30 transition-all"
                title="Exporter au format CSV"
              >
                <Download className="size-3.5" />
                <span className="hidden sm:inline">Exporter CSV</span>
              </button>
            )}
          </div>
        </div>

        <ErrorAlert message={error} />

        {/* ── Main Tab Navigation ── */}
        <div className="flex items-center gap-2 border-b border-[#E0E4E9] pb-px">
          <button
            type="button"
            onClick={() => setActiveTab('events')}
            className={cn(
              'flex items-center gap-2 px-4 py-3 text-xs sm:text-sm font-bold border-b-2 transition-all',
              activeTab === 'events'
                ? 'border-[#C0272D] text-[#C0272D] bg-white rounded-t-xl'
                : 'border-transparent text-[#3D5166] hover:text-[#0C1825]'
            )}
          >
            <Activity className="size-4" />
            <span>Journal d’Événements & Matériel</span>
            {logs.length > 0 && (
              <span className="text-[10px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-700 font-mono">
                {logs.length}
              </span>
            )}
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('osquery')}
            className={cn(
              'flex items-center gap-2 px-4 py-3 text-xs sm:text-sm font-bold border-b-2 transition-all',
              activeTab === 'osquery'
                ? 'border-[#C0272D] text-[#C0272D] bg-white rounded-t-xl'
                : 'border-transparent text-[#3D5166] hover:text-[#0C1825]'
            )}
          >
            <Terminal className="size-4" />
            <span>Terminal Osquery SQL</span>
            <span className="text-[10px] px-2 py-0.5 rounded-full bg-red-100 text-[#C0272D] font-bold">
              Live SQL
            </span>
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('packs')}
            className={cn(
              'flex items-center gap-2 px-4 py-3 text-xs sm:text-sm font-bold border-b-2 transition-all',
              activeTab === 'packs'
                ? 'border-[#C0272D] text-[#C0272D] bg-white rounded-t-xl'
                : 'border-transparent text-[#3D5166] hover:text-[#0C1825]'
            )}
          >
            <ShieldAlert className="size-4" />
            <span>Packs de Sécurité & Conformité</span>
            <span className="text-[10px] px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 font-bold">
              {presets.length} Packs
            </span>
          </button>
        </div>

        {/* ── TAB 1: Journal d'Événements & Matériel ── */}
        {activeTab === 'events' && (
          <div className="space-y-6">
            {/* Traffic KPIs */}
            {traffic && (
              <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <KpiMini
                  label="Total événements"
                  value={traffic.total_events}
                  icon={Activity}
                  color="text-[#C0272D]"
                  bgColor="bg-[#FDF2F2]"
                />
                <KpiMini
                  label="Clients actifs"
                  value={traffic.active_customers}
                  icon={Globe}
                  color="text-blue-600"
                  bgColor="bg-blue-50"
                />
                <KpiMini
                  label="Staff actifs"
                  value={traffic.active_staff}
                  icon={Shield}
                  color="text-emerald-600"
                  bgColor="bg-emerald-50"
                />
                <KpiMini
                  label="IPs uniques"
                  value={traffic.unique_ips ?? 0}
                  icon={Network}
                  color="text-purple-600"
                  bgColor="bg-purple-50"
                />
              </div>
            )}

            {/* Filters & Search Bar */}
            <div className="bg-white p-4 rounded-xl border border-[#E0E4E9] shadow-xs space-y-3">
              <div className="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
                {/* Search Input */}
                <div className="relative flex-1">
                  <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-gray-400" />
                  <input
                    type="text"
                    placeholder="Rechercher par action, acteur, système (Windows 11, Android, Mac), carte réseau (Wi-Fi, Ethernet, VMware), IP ou MAC..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="w-full pl-9 pr-4 py-2 text-xs bg-[#FAFBFD] rounded-lg border border-[#E0E4E9] placeholder:text-gray-400 focus:outline-hidden focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]/20"
                  />
                </div>

                {/* OS Filter Dropdown */}
                <div className="flex items-center gap-2">
                  <select
                    value={osFilter}
                    onChange={(e) => setOsFilter(e.target.value)}
                    className="text-xs px-3 py-2 rounded-lg border border-[#E0E4E9] bg-white text-gray-700 focus:outline-hidden focus:border-[#C0272D]"
                  >
                    <option value="all">Tous les Systèmes (OS)</option>
                    <option value="windows">Windows (11 / 10 Professionnel)</option>
                    <option value="android">Android (Samsung, Xiaomi, Pixel...)</option>
                    <option value="ios">iOS (iPhone & iPad)</option>
                    <option value="macos">macOS (MacBook, iMac)</option>
                    <option value="linux">Linux & ChromeOS</option>
                  </select>
                </div>
              </div>

              {/* Category Pills */}
              <div className="flex items-center gap-1.5 overflow-x-auto pt-1 pb-0.5">
                {CATEGORY_FILTERS.map((cat) => {
                  const Icon = cat.icon;
                  return (
                    <button
                      key={cat.id}
                      type="button"
                      onClick={() => setCategory(cat.id)}
                      className={`flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border transition-all whitespace-nowrap ${
                        category === cat.id
                          ? 'bg-[#C0272D] text-white border-[#C0272D] shadow-xs'
                          : 'bg-[#FAFBFD] text-[#3D5166] border-[#E0E4E9] hover:bg-gray-100'
                      }`}
                    >
                      <Icon className="size-3" />
                      {cat.label}
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Logs Table */}
            <div className="bg-white rounded-xl border border-[#E0E4E9] shadow-xs overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-[#FAFBFD] border-b border-[#E0E4E9] text-gray-600 font-semibold uppercase tracking-wider text-[11px]">
                    <tr>
                      <th className="px-4 py-3.5">Horodatage</th>
                      <th className="px-4 py-3.5">Action</th>
                      <th className="px-4 py-3.5">Acteur</th>
                      <th className="px-4 py-3.5">Système Exact & Modèle</th>
                      <th className="px-4 py-3.5">Carte Réseau & MAC</th>
                      <th className="px-4 py-3.5">IP & Localisation</th>
                      <th className="px-4 py-3.5">Dossier</th>
                      <th className="px-4 py-3.5 text-right">Détails</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#E0E4E9]">
                    {filteredLogs.length === 0 ? (
                      <tr>
                        <td colSpan={8} className="py-12 text-center text-gray-500 space-y-2">
                          <Activity className="size-8 text-gray-300 mx-auto" />
                          <p className="font-semibold text-gray-700">Aucun événement trouvé</p>
                          <p className="text-[11px] text-gray-400">Modifiez vos filtres ou effectuez une nouvelle recherche.</p>
                        </td>
                      </tr>
                    ) : (
                      filteredLogs.map((log) => (
                        <tr
                          key={log.id}
                          onClick={() => setSelectedLog(log)}
                          className="hover:bg-[#FAFBFD] transition-colors cursor-pointer"
                        >
                          {/* Timestamp */}
                          <td className="px-4 py-3.5 text-[11px] text-gray-600 font-mono whitespace-nowrap">
                            {new Date(log.created_at).toLocaleString('fr-FR', {
                              day: '2-digit',
                              month: '2-digit',
                              year: 'numeric',
                              hour: '2-digit',
                              minute: '2-digit',
                              second: '2-digit',
                            })}
                          </td>

                          {/* Action */}
                          <td className="px-4 py-3.5">
                            <span
                              className={`inline-flex items-center text-[10px] font-bold px-2 py-0.5 rounded-md border ${actionColor(
                                log.action
                              )}`}
                            >
                              {log.action.replace(/_/g, ' ')}
                            </span>
                          </td>

                          {/* Actor */}
                          <td className="px-4 py-3.5">
                            <div className="font-semibold text-[#0C1825] truncate max-w-[130px]">{log.actor.name}</div>
                            <div className="text-[10px] text-gray-500">
                              {log.actor.type === 'staff'
                                ? log.actor.role === 'admin'
                                  ? 'Administrateur'
                                  : 'Conseiller'
                                : log.actor.type === 'customer'
                                ? 'Client'
                                : 'Système'}
                            </div>
                          </td>

                          {/* OS & Computer Model */}
                          <td className="px-4 py-3.5">
                            <div>
                              {renderOsBadge(log.device)}
                              <div className="text-[10px] text-gray-600 truncate max-w-[190px] mt-0.5 font-medium">
                                {log.device?.computer_model || log.device?.device_model || 'PC / Machine'}
                              </div>
                            </div>
                          </td>

                          {/* Network Adapter & MAC */}
                          <td className="px-4 py-3.5">
                            <div className="flex items-center gap-1.5">
                              <span className="font-mono text-[11px] text-indigo-700 bg-indigo-50/70 px-1.5 py-0.5 rounded border border-indigo-100 font-semibold">
                                {log.device?.mac_address ?? '—'}
                              </span>
                            </div>
                            <div className="text-[10px] text-gray-600 flex items-center gap-1 mt-0.5 truncate max-w-[200px] font-medium">
                              <Wifi className="size-2.5 text-emerald-600 shrink-0" />
                              <span className="truncate">
                                {log.device?.network_adapters?.[0]?.name || 'Carte réseau sans fil Wi-Fi'}
                                {log.device?.network_adapters?.[0]?.ipv4 ? ` (${log.device.network_adapters[0].ipv4})` : ''}
                              </span>
                            </div>
                          </td>

                          {/* IP & Location */}
                          <td className="px-4 py-3.5">
                            <div className="font-mono text-[11px] text-gray-900 font-semibold">{log.ip_address ?? '—'}</div>
                            <div className="text-[10px] text-gray-500 flex items-center gap-1 mt-0.5 truncate max-w-[160px]">
                              <MapPin className="size-2.5 text-red-500 shrink-0" />
                              <span>{log.device?.location || 'Tunis, Tunisie 🇹🇳'}</span>
                            </div>
                          </td>

                          {/* Application */}
                          <td className="px-4 py-3.5">
                            {log.credit_application_id ? (
                              <span className="font-mono font-bold text-[#C0272D] text-[11px]">
                                #{log.credit_application_id}
                              </span>
                            ) : (
                              <span className="text-gray-400 text-[10px]">—</span>
                            )}
                          </td>

                          {/* Details Icon */}
                          <td className="px-4 py-3.5 text-right">
                            <div className="p-1.5 rounded-lg text-gray-400 hover:text-[#C0272D] hover:bg-red-50 inline-flex items-center justify-center transition-colors">
                              <Eye className="size-3.5" />
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              {/* Pagination Footer */}
              {totalPages > 1 && (
                <div className="p-4 bg-[#FAFBFD] border-t border-[#E0E4E9] flex items-center justify-between text-xs text-gray-600">
                  <div>
                    Page <span className="font-bold">{page}</span> sur <span className="font-bold">{totalPages}</span>
                  </div>
                  <div className="flex items-center gap-1">
                    <button
                      type="button"
                      disabled={page <= 1}
                      onClick={() => setPage((p) => Math.max(p - 1, 1))}
                      className="px-3 py-1.5 rounded-lg border border-[#E0E4E9] bg-white disabled:opacity-40 disabled:cursor-not-allowed hover:bg-gray-50 font-medium"
                    >
                      Précédent
                    </button>
                    <button
                      type="button"
                      disabled={page >= totalPages}
                      onClick={() => setPage((p) => Math.min(p + 1, totalPages))}
                      className="px-3 py-1.5 rounded-lg border border-[#E0E4E9] bg-white disabled:opacity-40 disabled:cursor-not-allowed hover:bg-gray-50 font-medium"
                    >
                      Suivant
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* ── TAB 2: Terminal Osquery SQL ── */}
        {activeTab === 'osquery' && (
          <OsqueryTerminal initialQuery={selectedOsqueryQuery} presets={presets} />
        )}

        {/* ── TAB 3: Packs de Sécurité Osquery ── */}
        {activeTab === 'packs' && (
          <OsqueryAuditPacks
            presets={presets}
            onRunQuery={(sql) => {
              setSelectedOsqueryQuery(sql);
              setActiveTab('osquery');
            }}
          />
        )}

        {/* ── Log Detail Modal ── */}
        {selectedLog && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/40 backdrop-blur-xs" onClick={() => setSelectedLog(null)} />
            <div className="relative bg-white rounded-2xl border border-[#E0E4E9] shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
              <div className="flex items-center justify-between px-6 py-4 border-b border-[#E0E4E9] sticky top-0 bg-white z-10">
                <div className="flex items-center gap-2">
                  <Activity className="size-4 text-[#C0272D]" />
                  <h3 className="text-sm font-bold text-[#0C1825]">
                    Détails Matériels, Cartes Réseau & Système d’Exploitation
                  </h3>
                </div>
                <button
                  type="button"
                  onClick={() => setSelectedLog(null)}
                  className="size-7 rounded-lg flex items-center justify-center text-[#3D5166] hover:bg-[#F4F6F8]"
                >
                  <X className="size-4" />
                </button>
              </div>

              <div className="px-6 py-5 space-y-5">
                {/* Action Osquery inspect banner */}
                <div className="bg-[#FDF2F2] border border-[#FECACA] rounded-xl p-3.5 flex items-center justify-between gap-3">
                  <div className="flex items-center gap-2.5">
                    <Terminal className="size-4 text-[#C0272D] shrink-0" />
                    <div>
                      <p className="text-xs font-bold text-[#0C1825]">Inspection Approfondie Osquery</p>
                      <p className="text-[11px] text-[#3D5166]">
                        Interroger directement l’hôte et vérifier les ports, interfaces et processus.
                      </p>
                    </div>
                  </div>
                  <button
                    type="button"
                    onClick={() => {
                      setSelectedOsqueryQuery('SELECT hostname, hardware_vendor, hardware_model, cpu_brand, physical_memory FROM system_info;');
                      setActiveTab('osquery');
                      setSelectedLog(null);
                    }}
                    className="px-3 py-1.5 text-xs font-bold text-white bg-[#C0272D] rounded-lg hover:bg-[#A01E23] transition-colors shrink-0 shadow-2xs"
                  >
                    Auditer avec Osquery
                  </button>
                </div>

                {/* Event Basic Info */}
                <div className="grid grid-cols-2 gap-3 bg-[#FAFBFD] p-3.5 rounded-xl border border-[#E0E4E9]">
                  <ModalRow label="Action Exécutée" value={selectedLog.action} />
                  <ModalRow
                    label="Horodatage"
                    value={new Date(selectedLog.created_at).toLocaleString('fr-FR')}
                  />
                  <ModalRow label="Acteur / Utilisateur" value={`${selectedLog.actor.name} (${selectedLog.actor.type})`} />
                  <ModalRow
                    label="Dossier de Crédit"
                    value={selectedLog.credit_application_id ? `#${selectedLog.credit_application_id}` : 'Aucun dossier'}
                  />
                </div>

                {/* Real Hardware & Computer Specs */}
                <div className="space-y-3">
                  <h4 className="text-xs font-bold text-[#0C1825] uppercase tracking-wider flex items-center gap-1.5">
                    <Laptop className="size-3.5 text-[#C0272D]" />
                    <span>Configuration Matérielle & Système Réel</span>
                  </h4>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-white p-3.5 rounded-xl border border-[#E0E4E9]">
                    <div className="sm:col-span-2">
                      <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">
                        Système d’Exploitation (OS Réel)
                      </p>
                      <div className="mt-1 flex items-center gap-2">
                        {renderOsBadge(selectedLog.device)}
                        <span className="text-xs font-bold text-[#0C1825]">
                          {selectedLog.device?.os}
                        </span>
                      </div>
                    </div>

                    {selectedLog.device?.computer_model && (
                      <div>
                        <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">
                          Modèle de la Machine (PC / Fabricant)
                        </p>
                        <p className="text-xs font-semibold text-[#0C1825] mt-0.5">
                          {selectedLog.device.computer_model}
                        </p>
                      </div>
                    )}

                    {selectedLog.device?.cpu && (
                      <div>
                        <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">
                          Processeur (CPU)
                        </p>
                        <p className="text-xs font-semibold text-[#0C1825] mt-0.5">
                          {selectedLog.device.cpu}
                        </p>
                      </div>
                    )}

                    {selectedLog.device?.ram && (
                      <div>
                        <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">
                          Mémoire Vive (RAM)
                        </p>
                        <p className="text-xs font-semibold text-[#0C1825] mt-0.5">
                          {selectedLog.device.ram}
                        </p>
                      </div>
                    )}

                    {selectedLog.device?.gpu && (
                      <div>
                        <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">
                          Carte Graphique (GPU)
                        </p>
                        <p className="text-xs font-semibold text-[#0C1825] mt-0.5">
                          {selectedLog.device.gpu}
                        </p>
                      </div>
                    )}

                    <div>
                      <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">
                        Navigateur Web & Version
                      </p>
                      <p className="text-xs font-semibold text-[#0C1825] mt-0.5">
                        {selectedLog.device?.browser || 'Navigateur Web'}{' '}
                        {selectedLog.device?.browser_version ? `v${selectedLog.device.browser_version}` : ''}
                      </p>
                    </div>

                    <div>
                      <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">
                        Localisation Géographique
                      </p>
                      <p className="text-xs font-semibold text-emerald-800 mt-0.5 flex items-center gap-1">
                        <MapPin className="size-3 text-red-500 shrink-0" />
                        <span>{selectedLog.device?.location || 'Tunis, Tunisie 🇹🇳'}</span>
                      </p>
                    </div>

                    <div>
                      <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">Adresse IP</p>
                      <p className="text-xs font-mono font-bold text-gray-900 mt-0.5">
                        {selectedLog.ip_address || '127.0.0.1'}
                      </p>
                    </div>

                    <div>
                      <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">
                        Adresse MAC Principale (Active)
                      </p>
                      <p className="text-xs font-mono font-bold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-100 mt-0.5 inline-block">
                        {selectedLog.device?.mac_address}
                      </p>
                    </div>
                  </div>
                </div>

                {/* Network Adapters List (Cartes Réseau) */}
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <h4 className="text-xs font-bold text-[#0C1825] uppercase tracking-wider flex items-center gap-1.5">
                      <Network className="size-3.5 text-[#C0272D]" />
                      <span>Cartes Réseau Détectées & Interfaces Matérielles</span>
                    </h4>
                    <span className="text-[10px] font-bold bg-[#F4F6F8] px-2 py-0.5 rounded-full text-gray-600 border border-[#E0E4E9]">
                      {selectedLog.device?.network_adapters?.length || 0} interfaces
                    </span>
                  </div>

                  <div className="divide-y divide-[#E0E4E9] border border-[#E0E4E9] rounded-xl overflow-hidden bg-[#FAFBFD]">
                    {(selectedLog.device?.network_adapters || []).map((adapter, idx) => (
                      <div key={idx} className="p-3.5 bg-white flex flex-col gap-2">
                        <div className="flex items-start justify-between gap-3">
                          <div className="flex items-start gap-2.5">
                            <div className="p-2 rounded-lg bg-gray-50 border border-gray-200 mt-0.5 shrink-0">
                              {renderAdapterIcon(adapter.type)}
                            </div>
                            <div>
                              <div className="flex items-center gap-2 flex-wrap">
                                <p className="text-xs font-bold text-[#0C1825]">{adapter.name}</p>
                                {adapter.is_primary_internet ? (
                                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[9px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300 shadow-2xs">
                                    🟢 Connecté à Internet (Connexion Principale)
                                  </span>
                                ) : adapter.is_active ? (
                                  <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                                    🔵 Réseau Local
                                  </span>
                                ) : (
                                  <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-medium bg-gray-100 text-gray-500">
                                    Média Déconnecté
                                  </span>
                                )}
                              </div>
                              <p className="text-[11px] text-gray-600 font-medium mt-0.5">{adapter.description}</p>
                            </div>
                          </div>

                          <div className="text-right shrink-0">
                            <span className="font-mono text-xs font-bold text-indigo-700 bg-indigo-50 px-2 py-1 rounded border border-indigo-200 inline-block">
                              {adapter.mac_address}
                            </span>
                          </div>
                        </div>

                        {/* Network Details (IP, Subnet, Gateway, IPv6) */}
                        {(adapter.ipv4 || adapter.gateway || adapter.ipv6) && (
                          <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2 border-t border-gray-100 text-[10px] bg-[#FAFBFD] p-2 rounded-lg">
                            {adapter.ipv4 && (
                              <div>
                                <span className="text-gray-500 font-medium">Adresse IPv4 :</span>
                                <p className="font-mono font-bold text-gray-900">{adapter.ipv4}</p>
                              </div>
                            )}
                            {adapter.subnet_mask && (
                              <div>
                                <span className="text-gray-500 font-medium">Masque de sous-réseau :</span>
                                <p className="font-mono text-gray-700">{adapter.subnet_mask}</p>
                              </div>
                            )}
                            {adapter.gateway && (
                              <div>
                                <span className="text-gray-500 font-medium">Passerelle par défaut :</span>
                                <p className="font-mono font-bold text-emerald-800">{adapter.gateway}</p>
                              </div>
                            )}
                            {adapter.ipv6 && (
                              <div className="sm:col-span-1 truncate">
                                <span className="text-gray-500 font-medium">Adresse IPv6 :</span>
                                <p className="font-mono text-gray-600 truncate" title={adapter.ipv6}>{adapter.ipv6}</p>
                              </div>
                            )}
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                </div>

                {/* Raw User Agent */}
                {selectedLog.user_agent && (
                  <div>
                    <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                      User-Agent HTTP Brut
                    </p>
                    <div className="text-[11px] bg-[#FAFBFD] rounded-xl p-3 border border-[#E0E4E9] font-mono text-gray-700 break-all">
                      {selectedLog.user_agent}
                    </div>
                  </div>
                )}

                {/* State Diff */}
                {selectedLog.previous_state && (
                  <div>
                    <p className="text-[10px] font-bold uppercase tracking-wider text-[#3D5166] mb-1">État Précédent</p>
                    <pre className="text-[11px] bg-[#F4F6F8] rounded-xl p-3 overflow-x-auto font-mono text-[#0C1825] max-h-32 border border-[#E0E4E9]">
                      {JSON.stringify(selectedLog.previous_state, null, 2)}
                    </pre>
                  </div>
                )}
                {selectedLog.new_state && (
                  <div>
                    <p className="text-[10px] font-bold uppercase tracking-wider text-emerald-800 mb-1">
                      Nouvel État Enregistré
                    </p>
                    <pre className="text-[11px] bg-emerald-50/60 rounded-xl p-3 overflow-x-auto font-mono text-emerald-950 max-h-32 border border-emerald-200">
                      {JSON.stringify(selectedLog.new_state, null, 2)}
                    </pre>
                  </div>
                )}
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function KpiMini({
  label,
  value,
  icon: Icon,
  color,
  bgColor,
}: {
  label: string;
  value: number;
  icon: React.ComponentType<{ className?: string }>;
  color: string;
  bgColor: string;
}) {
  return (
    <div className="bg-white rounded-xl border border-[#E0E4E9] shadow-xs p-4 flex items-center gap-3">
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
      <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">{label}</p>
      <p className="text-xs text-[#0C1825] font-semibold mt-0.5 break-all">{value}</p>
    </div>
  );
}
