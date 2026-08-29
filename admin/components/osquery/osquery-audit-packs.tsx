'use client';

import { useState } from 'react';
import {
  ShieldAlert,
  Network,
  Cpu,
  Activity,
  Users,
  HardDrive,
  Play,
  CheckCircle,
  Clock,
  Sparkles,
  Database,
  Search,
} from 'lucide-react';
import {
  type OsqueryPresetCategoryDto,
  type OsqueryQuickAuditDto,
  getOsqueryQuickAudit,
} from '@/lib/api/osquery';
import { cn } from '@/lib/utils';

interface OsqueryAuditPacksProps {
  presets: OsqueryPresetCategoryDto[];
  onRunQuery: (sql: string) => void;
}

export function OsqueryAuditPacks({ presets, onRunQuery }: OsqueryAuditPacksProps) {
  const [quickAudit, setQuickAudit] = useState<OsqueryQuickAuditDto | null>(null);
  const [runningQuick, setRunningQuick] = useState(false);
  const [activeCategory, setActiveCategory] = useState<string>('all');
  const [search, setSearch] = useState('');
  const [quickAuditError, setQuickAuditError] = useState<string | null>(null);

  async function handleRunQuickAudit() {
    setRunningQuick(true);
    setQuickAuditError(null);
    try {
      const data = await getOsqueryQuickAudit();
      setQuickAudit(data);
    } catch (auditFailure) {
      setQuickAuditError(auditFailure instanceof Error ? auditFailure.message : 'Impossible de lancer l’audit rapide.');
    }
    finally {
      setRunningQuick(false);
    }
  }

  const filteredPresets = presets.filter((cat) => {
    if (activeCategory !== 'all' && cat.category !== activeCategory) return false;
    if (search.trim()) {
      const q = search.toLowerCase();
      const matchCat = cat.title.toLowerCase().includes(q) || cat.description.toLowerCase().includes(q);
      const matchQueries = cat.queries.some(
        (query) => query.name.toLowerCase().includes(q) || query.description.toLowerCase().includes(q) || query.sql.toLowerCase().includes(q)
      );
      return matchCat || matchQueries;
    }
    return true;
  });

  const renderIcon = (iconName: string) => {
    switch (iconName) {
      case 'Network':
        return <Network className="size-4 text-blue-600" />;
      case 'Cpu':
        return <Cpu className="size-4 text-purple-600" />;
      case 'Activity':
        return <Activity className="size-4 text-amber-600" />;
      case 'Users':
        return <Users className="size-4 text-emerald-600" />;
      case 'HardDrive':
        return <HardDrive className="size-4 text-cyan-600" />;
      default:
        return <Database className="size-4 text-gray-600" />;
    }
  };

  const renderRiskBadge = (level: string) => {
    switch (level) {
      case 'critical':
        return (
          <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-50 text-red-700 border border-red-200">
            Critique / Sécurité
          </span>
        );
      case 'warning':
        return (
          <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
            Performance
          </span>
        );
      default:
        return (
          <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
            Information
          </span>
        );
    }
  };

  return (
    <div className="space-y-6">
      {/* ── Quick Security Scan Banner ── */}
      <div className="bg-linear-to-r from-[#0C1825] to-[#1A2E44] rounded-2xl p-6 text-white shadow-lg flex flex-col md:flex-row md:items-center justify-between gap-6 border border-slate-700">
        <div className="space-y-2 max-w-xl">
          <div className="flex items-center gap-2">
            <span className="px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-500/20 text-red-300 border border-red-500/30 flex items-center gap-1.5">
              <ShieldAlert className="size-3.5 text-red-400" />
              Scan de Conformité & Sécurité Système
            </span>
          </div>
          <h2 className="text-xl sm:text-2xl font-bold tracking-tight text-white">
            Audit Rapide du Système & Détection des Sockets
          </h2>
          <p className="text-xs sm:text-sm text-slate-300">
            Exécute instantanément un ensemble de requêtes Osquery coordonnées : analyse des ports ouverts, sessions d’utilisateurs actives, cartes réseau physiques et capacité disque.
          </p>
        </div>

        <div className="shrink-0 flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
          <button
            type="button"
            onClick={handleRunQuickAudit}
            disabled={runningQuick}
            className="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-[#C0272D] hover:bg-[#A01E23] text-white text-xs font-bold shadow-md hover:shadow-lg transition-all disabled:opacity-50"
          >
            <Sparkles className={`size-4 ${runningQuick ? 'animate-spin' : ''}`} />
            <span>{runningQuick ? 'Scan en cours...' : 'Lancer le Scan de Sécurité'}</span>
          </button>
        </div>
      </div>

      {quickAuditError && (
        <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700">
          {quickAuditError}
        </div>
      )}

      {/* Quick Audit Results Snapshot */}
      {quickAudit && (
        <div className="bg-white rounded-2xl border border-[#E0E4E9] p-5 shadow-xs space-y-4 animate-in fade-in slide-in-from-top-2 duration-200">
          <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-3">
            <div className="flex items-center gap-2">
              <CheckCircle className="size-4 text-emerald-600" />
              <h3 className="text-xs font-bold uppercase tracking-wider text-[#0C1825]">
                Résultat du Scan de Sécurité Instantané
              </h3>
            </div>
            <span className="text-[11px] text-gray-500 flex items-center gap-1 font-mono">
              <Clock className="size-3" />
              Exécuté en {quickAudit.execution_time_ms} ms
            </span>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div className="p-3 bg-[#FAFBFD] rounded-xl border border-[#E0E4E9]">
              <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">Ports TCP en Écoute</p>
              <p className="text-xl font-bold text-[#C0272D] mt-0.5">{quickAudit.open_ports_count}</p>
              <p className="text-[10px] text-gray-500 mt-0.5 font-mono">
                {quickAudit.open_ports.slice(0, 3).map((p) => `:${p.port}`).join(', ')}...
              </p>
            </div>

            <div className="p-3 bg-[#FAFBFD] rounded-xl border border-[#E0E4E9]">
              <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">Sessions Connectées</p>
              <p className="text-xl font-bold text-emerald-700 mt-0.5">{quickAudit.logged_users_count}</p>
              <p className="text-[10px] text-gray-500 mt-0.5 truncate">
                {quickAudit.logged_users.map((u) => u.user).join(', ')}
              </p>
            </div>

            <div className="p-3 bg-[#FAFBFD] rounded-xl border border-[#E0E4E9]">
              <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">Interfaces Réseau Actives</p>
              <p className="text-xl font-bold text-blue-700 mt-0.5">{quickAudit.active_interfaces_count}</p>
              <p className="text-[10px] text-gray-500 mt-0.5 truncate">
                {quickAudit.active_interfaces.map((i) => i.interface).join(', ')}
              </p>
            </div>

            <div className="p-3 bg-[#FAFBFD] rounded-xl border border-[#E0E4E9]">
              <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">Système & Modèle Hôte</p>
              <p className="text-xs font-bold text-[#0C1825] mt-1 truncate">
                {String(quickAudit.system?.hardware_model || quickAudit.os?.name || 'Hôte BTS')}
              </p>
              <p className="text-[10px] text-gray-500 mt-0.5 truncate">
                {String(quickAudit.system?.hostname || '127.0.0.1')}
              </p>
            </div>
          </div>
        </div>
      )}

      {/* ── Category Filters & Search ── */}
      <div className="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
        <div className="flex items-center gap-1.5 overflow-x-auto pb-1">
          <button
            type="button"
            onClick={() => setActiveCategory('all')}
            className={cn(
              'px-3 py-1.5 text-xs font-bold rounded-lg border transition-all whitespace-nowrap',
              activeCategory === 'all'
                ? 'bg-[#C0272D] text-white border-[#C0272D] shadow-xs'
                : 'bg-white text-gray-700 border-[#E0E4E9] hover:bg-gray-50'
            )}
          >
            Tous les Packs ({presets.reduce((acc, cat) => acc + cat.queries.length, 0)})
          </button>
          {presets.map((cat) => (
            <button
              key={cat.category}
              type="button"
              onClick={() => setActiveCategory(cat.category)}
              className={cn(
                'px-3 py-1.5 text-xs font-semibold rounded-lg border transition-all whitespace-nowrap flex items-center gap-1.5',
                activeCategory === cat.category
                  ? 'bg-[#C0272D] text-white border-[#C0272D] shadow-xs'
                  : 'bg-white text-gray-700 border-[#E0E4E9] hover:bg-gray-50'
              )}
            >
              {renderIcon(cat.icon)}
              <span>{cat.title}</span>
            </button>
          ))}
        </div>

        <div className="relative min-w-[240px]">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-3.5 text-gray-400" />
          <input
            type="text"
            placeholder="Rechercher un pack ou une requête..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-8 pr-3 py-1.5 text-xs bg-white rounded-lg border border-[#E0E4E9] placeholder:text-gray-400 focus:outline-hidden focus:border-[#C0272D]"
          />
        </div>
      </div>

      {/* ── Query Packs Grid ── */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {filteredPresets.map((category) => (
          <div
            key={category.category}
            className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs p-5 space-y-4 hover:shadow-md transition-shadow"
          >
            {/* Pack Header */}
            <div className="flex items-start justify-between gap-3">
              <div className="flex items-start gap-3">
                <div className="p-2.5 rounded-xl bg-[#F4F6F8] border border-[#E0E4E9] shrink-0">
                  {renderIcon(category.icon)}
                </div>
                <div>
                  <h3 className="text-sm font-bold text-[#0C1825]">{category.title}</h3>
                  <p className="text-xs text-[#3D5166] mt-0.5">{category.description}</p>
                </div>
              </div>
              {renderRiskBadge(category.risk_level)}
            </div>

            {/* Queries in Pack */}
            <div className="divide-y divide-[#F4F6F8] border border-[#E0E4E9] rounded-xl overflow-hidden bg-[#FAFBFD]">
              {category.queries.map((q) => (
                <div
                  key={q.name}
                  className="p-3.5 bg-white hover:bg-[#FAFBFD] flex items-center justify-between gap-3 transition-colors group"
                >
                  <div className="min-w-0 space-y-1">
                    <p className="text-xs font-bold text-[#0C1825] group-hover:text-[#C0272D] transition-colors">
                      {q.name}
                    </p>
                    <p className="text-[11px] text-gray-500 line-clamp-1">{q.description}</p>
                    <code className="text-[10px] font-mono text-gray-600 bg-[#F4F6F8] px-1.5 py-0.5 rounded border border-[#E0E4E9] inline-block truncate max-w-sm">
                      {q.sql}
                    </code>
                  </div>

                  <button
                    type="button"
                    onClick={() => onRunQuery(q.sql)}
                    className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white bg-[#C0272D] hover:bg-[#A01E23] rounded-lg shadow-xs transition-all shrink-0"
                    title="Exécuter dans le terminal"
                  >
                    <Play className="size-3 fill-current" />
                    <span>Lancer</span>
                  </button>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
