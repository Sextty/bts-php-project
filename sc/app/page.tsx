'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import {
  Activity,
  Network,
  Radio,
  ShieldAlert,
  UserX,
  RefreshCw,
  AlertTriangle,
  ArrowRight,
  Cpu,
  ChevronRight,
} from 'lucide-react';
import { getSecurityDashboard, SecurityDashboardData } from '@/lib/api/security';
import { getErrorMessage } from '@/lib/api/client';
import { formatDate } from '@/lib/utils';

export default function SecurityDashboardPage() {
  const [data, setData] = useState<SecurityDashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchDashboard = useCallback(async (showLoading = true) => {
    if (showLoading) setLoading(true);
    setError(null);
    try {
      const res = await getSecurityDashboard();
      setData(res);
    } catch (fetchError: unknown) {
      setError(getErrorMessage(fetchError));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    queueMicrotask(() => void fetchDashboard());
    const refresh = () => {
      if (document.visibilityState === 'visible') void fetchDashboard(false);
    };
    const interval = window.setInterval(refresh, 10_000);
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', refresh);
    return () => {
      window.clearInterval(interval);
      window.removeEventListener('focus', refresh);
      document.removeEventListener('visibilitychange', refresh);
    };
  }, [fetchDashboard]);

  return (
    <div className="sc-page">
      {/* ── Page Header ── */}
      <div className="sc-page-header flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="text-xl font-bold text-[#0C1825] tracking-tight">
              Tableau de bord — Security Operations Center (SOC)
            </h1>
            <span className={`flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold ${data ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : error ? 'border-red-200 bg-red-50 text-red-700' : 'border-slate-200 bg-slate-50 text-slate-600'}`}>
              <span className={`size-1.5 rounded-full ${data ? 'bg-emerald-500' : error ? 'bg-red-500' : 'bg-slate-400'}`} />
              {data ? 'API connectée' : error ? 'API indisponible' : 'Connexion…'}
            </span>
          </div>
          <p className="text-xs text-[#3D5166] mt-1">
            Vue actualisée du réseau, de la télémétrie système et de la traçabilité d’audit
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            onClick={() => void fetchDashboard()}
            disabled={loading}
            className="flex items-center gap-2 px-3.5 py-2 rounded-xl bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-xs font-medium text-[#3D5166] transition-colors disabled:opacity-50 shadow-2xs"
          >
            <RefreshCw className={`size-3.5 ${loading ? 'animate-spin' : ''}`} />
            <span>Actualiser</span>
          </button>
          <Link
            href="/network"
            className="flex items-center gap-2 px-4 py-2 rounded-xl bg-[#C0272D] hover:bg-[#A01F24] text-white text-xs font-semibold shadow-xs transition-colors"
          >
            <Network className="size-3.5" />
            <span>SC Réseau</span>
          </Link>
        </div>
      </div>

      {error && (
        <div className="p-4 rounded-xl bg-red-50 border border-red-200 flex items-center justify-between text-xs text-red-700">
          <div className="flex items-center gap-3">
            <AlertTriangle className="size-5 text-red-600 shrink-0" />
            <div>
              <div className="font-semibold">Erreur de connexion à l’API de télémétrie</div>
              <div className="text-red-600/80 mt-0.5">{error}</div>
            </div>
          </div>
          <button
            onClick={() => void fetchDashboard()}
            className="px-3 py-1 bg-red-100 hover:bg-red-200 text-red-800 rounded-lg font-medium transition-colors"
          >
            Réessayer
          </button>
        </div>
      )}

      {loading && !data ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className="h-32 bg-white border border-[#E0E4E9] rounded-xl animate-pulse shadow-2xs"></div>
          ))}
        </div>
      ) : data ? (
        <>
          {/* ── Network Agent & Architecture Status Banner ── */}
          <div className="p-5 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div className="flex items-center gap-3.5">
              <div className="size-11 rounded-xl bg-[#FDF2F2] border border-[#F5C2C4] flex items-center justify-center text-[#C0272D] shrink-0">
                <Cpu className="size-5" />
              </div>
              <div>
                <div className="flex items-center gap-2">
                  <h3 className="text-sm font-bold text-[#0C1825]">Télémétrie de l’hôte</h3>
                  <span className="px-2 py-0.5 text-[10px] font-bold bg-emerald-100 text-emerald-800 rounded">
                    Données disponibles
                  </span>
                </div>
                <p className="text-xs text-[#3D5166] mt-0.5">
                  Nœud : <strong className="text-[#0C1825]">{data.system.os_name}</strong> ({data.system.platform}) • Disponibilité : {data.system.uptime_days}j {data.system.uptime_hours}h
                </p>
              </div>
            </div>

            <div className="flex items-center gap-2">
              <Link
                href="/network"
                className="px-3.5 py-1.5 rounded-lg bg-[#F4F6F8] hover:bg-[#E0E4E9] text-xs font-medium text-[#3D5166] transition-colors flex items-center gap-1.5"
              >
                <span>Détails Réseau</span>
                <ChevronRight className="size-3.5" />
              </Link>
              <Link
                href="/alerts"
                className="px-3.5 py-1.5 rounded-lg bg-[#FDF2F2] hover:bg-[#FCE8E8] text-xs font-semibold text-[#C0272D] transition-colors flex items-center gap-1.5"
              >
                <span>Alertes de Sécurité</span>
                <ChevronRight className="size-3.5" />
              </Link>
            </div>
          </div>

          {/* ── 4 KPI Cards (SC Réseau Pillars) ── */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {/* 1. Port Scanner KPI */}
            <div className="p-5 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs hover:border-[#CBD5E1] transition-colors">
              <div className="flex items-center justify-between text-xs text-[#3D5166] mb-2">
                <span className="font-semibold">Port Scanner (Sockets)</span>
                <div className="size-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                  <Radio className="size-4" />
                </div>
              </div>
              <div className="text-2xl font-bold text-[#0C1825] tracking-tight">
                {data.metrics.open_ports_count}
              </div>
              <div className="text-[11px] text-[#3D5166] mt-2 flex items-center justify-between border-t border-[#F4F6F8] pt-2">
                <span>Ports analysés</span>
                <Link href="/network?tab=scanner" className="text-[#C0272D] font-semibold hover:underline">
                  Inspecter &rarr;
                </Link>
              </div>
            </div>

            {/* 2. Socket Monitor KPI */}
            <div className="p-5 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs hover:border-[#CBD5E1] transition-colors">
              <div className="flex items-center justify-between text-xs text-[#3D5166] mb-2">
                <span className="font-semibold">Socket Monitor</span>
                <div className="size-8 rounded-lg bg-cyan-50 text-cyan-600 flex items-center justify-center">
                  <Network className="size-4" />
                </div>
              </div>
              <div className="text-2xl font-bold text-[#0C1825] tracking-tight">
                {data.metrics.open_ports_count} sockets
              </div>
              <div className="text-[11px] text-[#3D5166] mt-2 flex items-center justify-between border-t border-[#F4F6F8] pt-2">
                <span>Processus liés</span>
                <span className="text-emerald-700 font-medium">Dernière lecture disponible</span>
              </div>
            </div>

            {/* 3. Traffic Monitor KPI */}
            <div className="p-5 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs hover:border-[#CBD5E1] transition-colors">
              <div className="flex items-center justify-between text-xs text-[#3D5166] mb-2">
                <span className="font-semibold">Traffic Monitor (Aujourd’hui)</span>
                <div className="size-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                  <Activity className="size-4" />
                </div>
              </div>
              <div className="text-2xl font-bold text-[#0C1825] tracking-tight">
                {data.metrics.events_today.toLocaleString()}
              </div>
              <div className="text-[11px] text-[#3D5166] mt-2 flex items-center justify-between border-t border-[#F4F6F8] pt-2">
                <span>Total historique</span>
                <span className="text-[#0C1825] font-semibold">{data.metrics.total_events.toLocaleString()}</span>
              </div>
            </div>

            {/* 4. Suspended Accounts & Security Events */}
            <div className="p-5 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs hover:border-[#CBD5E1] transition-colors">
              <div className="flex items-center justify-between text-xs text-[#3D5166] mb-2">
                <span className="font-semibold">Comptes Suspendus</span>
                <div className="size-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                  <UserX className="size-4" />
                </div>
              </div>
              <div className="text-2xl font-bold text-amber-600 tracking-tight">
                {data.metrics.suspended_customers}
              </div>
              <div className="text-[11px] text-[#3D5166] mt-2 flex items-center justify-between border-t border-[#F4F6F8] pt-2">
                <span>Clients actifs</span>
                <span className="text-[#0C1825] font-semibold">{data.metrics.active_customers}</span>
              </div>
            </div>
          </div>

          {/* ── Main 2-Column Section: Recent Audit & Top Actions ── */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Recent Audit Journal Stream (2 cols) */}
            <div className="lg:col-span-2 bg-white border border-[#E0E4E9] rounded-2xl p-6 shadow-xs">
              <div className="flex items-center justify-between pb-4 mb-4 border-b border-[#E0E4E9]">
                <div className="flex items-center gap-2.5">
                  <ShieldAlert className="size-4 text-[#C0272D]" />
                  <h2 className="text-sm font-bold text-[#0C1825]">Événements d’Audit & Réseau Récents</h2>
                </div>
                <Link
                  href="/activity"
                  className="text-xs font-semibold text-[#C0272D] hover:underline flex items-center gap-1"
                >
                  <span>Consulter le journal</span>
                  <ArrowRight className="size-3.5" />
                </Link>
              </div>

              {data.recent_events.length === 0 ? (
                <div className="py-12 text-center text-xs text-[#3D5166]">
                  Aucun événement d’audit récent
                </div>
              ) : (
                <div className="space-y-2.5">
                  {data.recent_events.map((evt) => (
                    <div
                      key={evt.id}
                      className="p-3.5 rounded-xl bg-[#F8FAFC] border border-[#E0E4E9] hover:bg-[#F1F5F9] transition-colors flex items-center justify-between gap-4 text-xs"
                    >
                      <div className="min-w-0 flex items-center gap-3">
                        <span className="size-2 rounded-full bg-[#C0272D] shrink-0"></span>
                        <div className="min-w-0">
                          <div className="font-semibold text-[#0C1825] truncate">{evt.action}</div>
                          <div className="text-[11px] text-[#3D5166] flex items-center gap-2 mt-0.5">
                            <span>{evt.actor.name} ({evt.actor.type})</span>
                            {evt.ip_address && (
                              <>
                                <span>•</span>
                                <span className="font-mono text-[#0C1825]">{evt.ip_address}</span>
                              </>
                            )}
                          </div>
                        </div>
                      </div>
                      <div className="text-[11px] text-[#3D5166] shrink-0">
                        {formatDate(evt.created_at)}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Top Security & Network Actions (1 col) */}
            <div className="bg-white border border-[#E0E4E9] rounded-2xl p-6 shadow-xs">
              <div className="pb-4 mb-4 border-b border-[#E0E4E9] flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Activity className="size-4 text-[#C0272D]" />
                  <h2 className="text-sm font-bold text-[#0C1825]">Actions Fréquentes (7j)</h2>
                </div>
                <span className="text-[10px] font-bold text-[#3D5166] uppercase bg-[#F4F6F8] px-2 py-0.5 rounded">
                  Trafic 7J
                </span>
              </div>

              {data.traffic.top_actions.length === 0 ? (
                <div className="py-12 text-center text-xs text-[#3D5166]">
                  Aucune donnée
                </div>
              ) : (
                <div className="space-y-3.5">
                  {data.traffic.top_actions.map((item) => (
                    <div key={item.action} className="space-y-1">
                      <div className="flex items-center justify-between text-xs">
                        <span className="text-[#3D5166] truncate max-w-[200px] font-medium">{item.action}</span>
                        <span className="text-[#0C1825] font-bold">{item.count}</span>
                      </div>
                      <progress
                        value={item.count}
                        max={data.traffic.top_actions[0]?.count || 1}
                        aria-label={`${item.action} : ${item.count}`}
                        className="h-1.5 w-full overflow-hidden rounded-full accent-[#C0272D]"
                      />
                    </div>
                  ))}
                </div>
              )}

              {data.traffic.unique_ips !== null && (
                <div className="mt-6 pt-4 border-t border-[#E0E4E9] flex items-center justify-between text-xs">
                  <span className="text-[#3D5166]">Adresses IP Uniques (7j) :</span>
                  <span className="text-[#0C1825] font-bold font-mono">{data.traffic.unique_ips}</span>
                </div>
              )}
            </div>
          </div>
        </>
      ) : null}
    </div>
  );
}
