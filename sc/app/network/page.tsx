'use client';

import { useCallback, useState, useEffect } from 'react';
import {
  Radio,
  Cpu,
  Server,
  RefreshCw,
} from 'lucide-react';
import {
  getSecurityTelemetry,
  getSecurityVulnerabilities,
  scanSecurityVulnerabilities,
  getSecurityDashboard,
  SecurityTelemetryData,
  SecurityVulnerabilitiesData,
  SecurityDashboardData,
} from '@/lib/api/security';
import { formatDate } from '@/lib/utils';
import { getErrorMessage } from '@/lib/api/client';
import { StatusMessage } from '@/components/status-message';

const NETWORK_TABS = ['scanner', 'sockets', 'traffic', 'agent'] as const;

export default function SecurityNetworkPage() {
  const [tab, setTab] = useState<(typeof NETWORK_TABS)[number]>('scanner');
  const [telemetry, setTelemetry] = useState<SecurityTelemetryData | null>(null);
  const [vulns, setVulns] = useState<SecurityVulnerabilitiesData | null>(null);
  const [dashboard, setDashboard] = useState<SecurityDashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [scanning, setScanning] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const memoryTotalBytes = Number(telemetry?.memory_info?.memory_total ?? 0);

  const fetchAllNetworkData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [telemetryResult, vulnerabilitiesResult, dashboardResult] = await Promise.allSettled([
        getSecurityTelemetry(),
        getSecurityVulnerabilities(),
        getSecurityDashboard(),
      ]);
      if (telemetryResult.status === 'fulfilled') setTelemetry(telemetryResult.value);
      if (vulnerabilitiesResult.status === 'fulfilled') setVulns(vulnerabilitiesResult.value);
      if (dashboardResult.status === 'fulfilled') setDashboard(dashboardResult.value);
      const failure = [telemetryResult, vulnerabilitiesResult, dashboardResult].find((result) => result.status === 'rejected');
      if (failure?.status === 'rejected') setError(getErrorMessage(failure.reason, 'Certaines données réseau sont indisponibles.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    queueMicrotask(() => void fetchAllNetworkData());
    const requestedTab = new URLSearchParams(window.location.search).get('tab');
    if (requestedTab && NETWORK_TABS.includes(requestedTab as (typeof NETWORK_TABS)[number])) {
      queueMicrotask(() => setTab(requestedTab as (typeof NETWORK_TABS)[number]));
    }
  }, [fetchAllNetworkData]);

  const handleScan = async () => {
    setScanning(true);
    try {
      const res = await scanSecurityVulnerabilities();
      setVulns(res);
    } catch (scanError: unknown) {
      setError(`Erreur de scan : ${getErrorMessage(scanError, 'Échec du scan')}`);
    } finally {
      setScanning(false);
    }
  };

  return (
    <div className="sc-page">
      {/* ── Page Header ── */}
      <div className="sc-page-header flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="text-xl font-bold text-[#0C1825] tracking-tight">
              SC RÉSEAU — Infrastructure, Sockets & Sondes Réseau
            </h1>
            <span className="px-2.5 py-0.5 text-xs font-semibold bg-[#FDF2F2] text-[#C0272D] border border-[#F5C2C4] rounded-full">
              Sonde Network Agent
            </span>
          </div>
          <p className="text-xs text-[#3D5166] mt-1">
            Surveillance intégrée des ports d’écoute, sockets système, trafic d’audit et composants matériels
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            onClick={() => void fetchAllNetworkData()}
            disabled={loading}
            className="flex items-center gap-2 px-3.5 py-2 rounded-xl bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-xs font-medium text-[#3D5166] transition-colors disabled:opacity-50 shadow-2xs"
          >
            <RefreshCw className={`size-3.5 ${loading ? 'animate-spin' : ''}`} />
            <span>Actualiser</span>
          </button>
          <button
            onClick={handleScan}
            disabled={scanning}
            className="flex items-center gap-2 px-4 py-2 rounded-xl bg-[#C0272D] hover:bg-[#A01F24] text-white text-xs font-semibold shadow-xs transition-colors disabled:opacity-50"
          >
            <Radio className={`size-3.5 ${scanning ? 'animate-spin' : ''}`} />
            <span>{scanning ? 'Scan en cours...' : 'Lancer un Scan Réseau'}</span>
          </button>
        </div>
      </div>

      <StatusMessage message={error} />

      {/* ── Navigation Tabs ── */}
      <div role="tablist" aria-label="Modules réseau" className="p-1.5 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs flex flex-wrap gap-1.5">
        <button
          type="button"
          role="tab"
          aria-selected={tab === 'scanner'}
          onClick={() => setTab('scanner')}
          className={`px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
            tab === 'scanner'
              ? 'bg-[#C0272D] text-white shadow-xs'
              : 'text-[#3D5166] hover:bg-[#F4F6F8] hover:text-[#0C1825]'
          }`}
        >
          Port Scanner ({vulns?.ports_analyzed_count ?? 0})
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={tab === 'sockets'}
          onClick={() => setTab('sockets')}
          className={`px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
            tab === 'sockets'
              ? 'bg-[#C0272D] text-white shadow-xs'
              : 'text-[#3D5166] hover:bg-[#F4F6F8] hover:text-[#0C1825]'
          }`}
        >
          Socket Monitor ({telemetry?.listening_ports.length ?? 0})
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={tab === 'traffic'}
          onClick={() => setTab('traffic')}
          className={`px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
            tab === 'traffic'
              ? 'bg-[#C0272D] text-white shadow-xs'
              : 'text-[#3D5166] hover:bg-[#F4F6F8] hover:text-[#0C1825]'
          }`}
        >
          Traffic Monitor
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={tab === 'agent'}
          onClick={() => setTab('agent')}
          className={`px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
            tab === 'agent'
              ? 'bg-[#C0272D] text-white shadow-xs'
              : 'text-[#3D5166] hover:bg-[#F4F6F8] hover:text-[#0C1825]'
          }`}
        >
          Network Agent (Hôte & Cartes)
        </button>
      </div>

      {/* ── PORT SCANNER ── */}
      {tab === 'scanner' && (
        <div className="space-y-6">
          <div className="bg-white border border-[#E0E4E9] rounded-2xl p-6 shadow-xs space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-[#E0E4E9] pb-4">
              <div>
                <h2 className="text-sm font-bold text-[#0C1825]">Résultats du Scan des Ports d’Écoute</h2>
                <p className="text-xs text-[#3D5166] mt-0.5">
                  Dernier scan : {formatDate(vulns?.last_scanned_at)} • {vulns?.ports_analyzed_count ?? 0} ports inspectés
                </p>
              </div>
              <span
                className={`px-3 py-1 rounded-full text-xs font-bold ${
                  vulns?.summary.overall_risk === 'CRITICAL'
                    ? 'bg-red-100 text-red-700'
                    : vulns?.summary.overall_risk === 'WARNING'
                    ? 'bg-amber-100 text-amber-700'
                    : 'bg-emerald-100 text-emerald-700'
                }`}
              >
                Risque Global : {vulns?.summary.overall_risk ?? 'HEALTHY'}
              </span>
            </div>

            {vulns?.findings.length === 0 ? (
              <div className="py-8 text-center text-xs text-slate-500">
                Aucune vulnérabilité détectée sur les ports inspectés.
              </div>
            ) : (
              <div className="space-y-3">
                {vulns?.findings.map((f, idx) => (
                  <div
                    key={idx}
                    className="p-4 rounded-xl border border-[#E0E4E9] bg-[#F8FAFC] space-y-1.5 text-xs"
                  >
                    <div className="flex items-center justify-between">
                      <span
                        className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                          f.severity === 'critical'
                            ? 'bg-red-100 text-red-700 border border-red-200'
                            : f.severity === 'warning'
                            ? 'bg-amber-100 text-amber-700 border border-amber-200'
                            : 'bg-cyan-100 text-cyan-700 border border-cyan-200'
                        }`}
                      >
                        {f.severity}
                      </span>
                      <span className="font-mono text-[#3D5166]">Port {f.port} ({f.address})</span>
                    </div>
                    <div className="font-bold text-[#0C1825]">{f.title}</div>
                    <div className="text-[#3D5166]">{f.description}</div>
                    <div className="text-emerald-700 font-medium">
                      <span className="text-[#3D5166]">Recommandation : </span>
                      {f.recommendation}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── TAB 3: SOCKET MONITOR ── */}
      {tab === 'sockets' && (
        <div className="bg-white border border-[#E0E4E9] rounded-2xl p-6 shadow-xs space-y-4">
          <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-4">
            <div>
              <h2 className="text-sm font-bold text-[#0C1825]">Socket Monitor — Sockets & Ports d’Écoute</h2>
              <p className="text-xs text-[#3D5166] mt-0.5">
                Dernière lecture des sockets système et des processus associés
              </p>
            </div>
            <span className="text-xs font-semibold text-[#3D5166]">
              {telemetry?.listening_ports.length ?? 0} sockets
            </span>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-[#F8FAFC] border-b border-[#E0E4E9] text-[#3D5166] uppercase text-[10px] font-bold">
                <tr>
                  <th className="py-3 px-4">Port</th>
                  <th className="py-3 px-4">Adresse de Liaison</th>
                  <th className="py-3 px-4">Protocole</th>
                  <th className="py-3 px-4">PID</th>
                  <th className="py-3 px-4">Processus Lié</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#E0E4E9]">
                {telemetry?.listening_ports.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="py-8 text-center text-slate-500">Aucune donnée</td>
                  </tr>
                ) : (
                  telemetry?.listening_ports.map((p, idx) => (
                    <tr key={idx} className="hover:bg-[#F8FAFC] transition-colors">
                      <td className="py-3 px-4 font-bold text-[#C0272D] font-mono">{p.port}</td>
                      <td className="py-3 px-4 font-mono text-[#0C1825]">{p.address}</td>
                      <td className="py-3 px-4 font-bold text-[#3D5166]">
                        {p.protocol === 6 ? 'TCP' : p.protocol === 17 ? 'UDP' : p.protocol}
                      </td>
                      <td className="py-3 px-4 font-mono text-[#3D5166]">{p.pid}</td>
                      <td className="py-3 px-4 font-semibold text-[#0C1825]">{p.process_name || '—'}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ── TAB 4: TRAFFIC MONITOR ── */}
      {tab === 'traffic' && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div className="bg-white border border-[#E0E4E9] rounded-2xl p-6 shadow-xs space-y-4">
            <h2 className="text-sm font-bold text-[#0C1825] border-b border-[#E0E4E9] pb-3">
              Historique de Trafic & Événements d’Audit (7 jours)
            </h2>
            <div className="space-y-3">
              {dashboard?.traffic.timeline.map((point) => (
                <div key={point.date} className="flex items-center justify-between text-xs">
                  <span className="font-mono text-[#3D5166]">{point.date}</span>
                  <span className="font-bold text-[#0C1825]">{point.events} événements</span>
                </div>
              ))}
            </div>
            {dashboard?.traffic.unique_ips !== null && (
              <div className="pt-4 border-t border-[#E0E4E9] flex items-center justify-between text-xs">
                <span className="text-[#3D5166]">Adresses IP uniques :</span>
                <span className="font-bold font-mono text-[#0C1825]">{dashboard?.traffic.unique_ips}</span>
              </div>
            )}
          </div>

          <div className="bg-white border border-[#E0E4E9] rounded-2xl p-6 shadow-xs space-y-4">
            <h2 className="text-sm font-bold text-[#0C1825] border-b border-[#E0E4E9] pb-3">
              Top Actions Réseau & Authentification
            </h2>
            <div className="space-y-3">
              {dashboard?.traffic.top_actions.map((act) => (
                <div key={act.action} className="space-y-1">
                  <div className="flex items-center justify-between text-xs">
                    <span className="text-[#3D5166] font-medium truncate max-w-[200px]">{act.action}</span>
                    <span className="text-[#0C1825] font-bold">{act.count}</span>
                  </div>
                  <progress value={act.count} max={dashboard?.traffic.top_actions[0]?.count || 1} aria-label={`${act.action} : ${act.count}`} className="h-1.5 w-full accent-[#C0272D]" />
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* ── TAB 5: NETWORK AGENT (HARDWARE & INTERFACES) ── */}
      {tab === 'agent' && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            {/* Host Hardware Info */}
            <div className="bg-white border border-[#E0E4E9] rounded-2xl p-6 shadow-xs space-y-3 text-xs">
              <div className="flex items-center gap-2 font-bold text-[#0C1825] border-b border-[#E0E4E9] pb-3">
                <Cpu className="size-4 text-[#C0272D]" />
                <span>Hardware Info (system_info)</span>
              </div>
              <div className="space-y-2 text-[#3D5166]">
                <div className="flex justify-between">
                  <span>Nom d’hôte :</span>
                  <span className="font-bold text-[#0C1825]">{telemetry?.system_info?.hostname || 'N/A'}</span>
                </div>
                <div className="flex justify-between">
                  <span>Processeur (CPU) :</span>
                  <span className="text-[#0C1825] truncate max-w-[220px]">{telemetry?.system_info?.cpu_brand || 'Inconnu'}</span>
                </div>
                <div className="flex justify-between">
                  <span>Cœurs logiques :</span>
                  <span className="text-[#0C1825] font-bold">{telemetry?.system_info?.cpu_logical_cores || 'N/A'}</span>
                </div>
                <div className="flex justify-between">
                  <span>Mémoire Totale :</span>
                  <span className="text-[#0C1825]">
                    {Number.isFinite(memoryTotalBytes) && memoryTotalBytes > 0 ? `${(memoryTotalBytes / (1024 * 1024 * 1024)).toFixed(2)} Go` : 'N/A'}
                  </span>
                </div>
              </div>
            </div>

            {/* Operating System */}
            <div className="bg-white border border-[#E0E4E9] rounded-2xl p-6 shadow-xs space-y-3 text-xs">
              <div className="flex items-center gap-2 font-bold text-[#0C1825] border-b border-[#E0E4E9] pb-3">
                <Server className="size-4 text-cyan-600" />
                <span>Système d’Exploitation (os_version)</span>
              </div>
              <div className="space-y-2 text-[#3D5166]">
                <div className="flex justify-between">
                  <span>Système :</span>
                  <span className="font-bold text-[#0C1825]">{telemetry?.os_version?.name || 'N/A'}</span>
                </div>
                <div className="flex justify-between">
                  <span>Plateforme :</span>
                  <span className="text-[#0C1825]">{telemetry?.os_version?.platform || 'N/A'}</span>
                </div>
                <div className="flex justify-between">
                  <span>Version / Build :</span>
                  <span className="text-[#0C1825]">{telemetry?.os_version?.version || telemetry?.os_version?.build || 'N/A'}</span>
                </div>
                <div className="flex justify-between">
                  <span>Disponibilité :</span>
                  <span className="font-bold text-emerald-700">
                    {telemetry?.uptime?.days || 0}j {telemetry?.uptime?.hours || 0}h
                  </span>
                </div>
              </div>
            </div>
          </div>

          {/* Network Interfaces */}
          <div className="bg-white border border-[#E0E4E9] rounded-2xl p-6 shadow-xs space-y-4">
            <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-3">
              <h3 className="text-sm font-bold text-[#0C1825]">Cartes & Interfaces Réseau (interface_details)</h3>
              <span className="text-xs text-[#3D5166]">{telemetry?.interfaces.length ?? 0} cartes</span>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {telemetry?.interfaces.map((iface, idx) => (
                <div key={idx} className="p-3.5 bg-[#F8FAFC] border border-[#E0E4E9] rounded-xl space-y-1 text-xs">
                  <div className="flex items-center justify-between font-bold text-[#0C1825]">
                    <span>{iface.interface}</span>
                    <span className="text-[10px] uppercase px-2 py-0.5 rounded bg-white border border-[#E0E4E9]">
                      {iface.type || 'Ethernet'}
                    </span>
                  </div>
                  <div className="flex justify-between text-[#3D5166]">
                    <span>Adresse MAC :</span>
                    <span className="font-mono text-[#0C1825]">{iface.mac || '—'}</span>
                  </div>
                  <div className="flex justify-between text-[#3D5166]">
                    <span>MTU :</span>
                    <span className="text-[#0C1825]">{iface.mtu || '—'}</span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
