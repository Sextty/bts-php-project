'use client';

import { useCallback, useState, useEffect } from 'react';
import Link from 'next/link';
import {
  ShieldAlert,
  AlertTriangle,
  RefreshCw,
  Radio,
  ArrowRight,
  UserX,
} from 'lucide-react';
import {
  getSecurityVulnerabilities,
  scanSecurityVulnerabilities,
  getSecurityUsers,
  SecurityVulnerabilitiesData,
  SecurityUsersData,
} from '@/lib/api/security';
import { formatDate } from '@/lib/utils';
import { getErrorMessage } from '@/lib/api/client';
import { StatusMessage } from '@/components/status-message';

export default function SecurityAlertsPage() {
  const [vulns, setVulns] = useState<SecurityVulnerabilitiesData | null>(null);
  const [usersData, setUsersData] = useState<SecurityUsersData | null>(null);
  const [severityFilter, setSeverityFilter] = useState<'all' | 'critical' | 'warning' | 'info'>('all');
  const [scanning, setScanning] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadAlerts = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [vulnerabilityResult, userResult] = await Promise.allSettled([
        getSecurityVulnerabilities(),
        getSecurityUsers({ status: 'suspended' }),
      ]);
      if (vulnerabilityResult.status === 'fulfilled') setVulns(vulnerabilityResult.value);
      if (userResult.status === 'fulfilled') setUsersData(userResult.value);
      const failure = [vulnerabilityResult, userResult].find((result) => result.status === 'rejected');
      if (failure?.status === 'rejected') setError(getErrorMessage(failure.reason, 'Certaines alertes sont indisponibles.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    queueMicrotask(() => void loadAlerts());
  }, [loadAlerts]);

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

  const filteredFindings = (vulns?.findings || []).filter((f) => {
    if (severityFilter === 'all') return true;
    return f.severity === severityFilter;
  });

  const suspendedCustomers = usersData?.customers.items || [];

  return (
    <div className="sc-page">
      {/* ── Page Header ── */}
      <div className="sc-page-header flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="text-xl font-bold text-[#0C1825] tracking-tight">
              Centre d’Alertes de Sécurité (SOC Alerts)
            </h1>
            <span className="px-2.5 py-0.5 text-xs font-semibold bg-red-50 text-red-700 border border-red-200 rounded-full">
              {vulns?.summary.critical_count ?? 0} Alertes Critiques
            </span>
          </div>
          <p className="text-xs text-[#3D5166] mt-1">
            Détection des configurations vulnérables, protocoles en clair et comptes suspendus sous surveillance
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            onClick={() => void loadAlerts()}
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
            <span>{scanning ? 'Scan en cours...' : 'Lancer un Scan'}</span>
          </button>
        </div>
      </div>

      <StatusMessage message={error} />

      {/* ── Summary Counters ── */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="p-5 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs flex items-center justify-between">
          <div>
            <span className="text-xs font-semibold text-[#3D5166]">Risque Critique</span>
            <div className="text-2xl font-bold text-red-600 tracking-tight mt-1">
              {vulns?.summary.critical_count ?? 0}
            </div>
          </div>
          <div className="size-10 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">
            <AlertTriangle className="size-5" />
          </div>
        </div>

        <div className="p-5 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs flex items-center justify-between">
          <div>
            <span className="text-xs font-semibold text-[#3D5166]">Avertissements Réseau</span>
            <div className="text-2xl font-bold text-amber-600 tracking-tight mt-1">
              {vulns?.summary.warning_count ?? 0}
            </div>
          </div>
          <div className="size-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
            <ShieldAlert className="size-5" />
          </div>
        </div>

        <div className="p-5 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs flex items-center justify-between">
          <div>
            <span className="text-xs font-semibold text-[#3D5166]">Comptes Bloqués (Isolations)</span>
            <div className="text-2xl font-bold text-[#0C1825] tracking-tight mt-1">
              {suspendedCustomers.length}
            </div>
          </div>
          <div className="size-10 rounded-xl bg-slate-50 text-slate-700 flex items-center justify-center">
            <UserX className="size-5" />
          </div>
        </div>
      </div>

      {/* ── Filter Bar ── */}
      <div className="flex items-center gap-2 pb-2">
        <button
          onClick={() => setSeverityFilter('all')}
          className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${
            severityFilter === 'all'
              ? 'bg-[#0C1825] text-white'
              : 'bg-white text-[#3D5166] border border-[#E0E4E9] hover:bg-[#F4F6F8]'
          }`}
        >
          Toutes les alertes ({vulns?.findings.length ?? 0})
        </button>
        <button
          onClick={() => setSeverityFilter('critical')}
          className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${
            severityFilter === 'critical'
              ? 'bg-red-600 text-white'
              : 'bg-white text-[#3D5166] border border-[#E0E4E9] hover:bg-[#F4F6F8]'
          }`}
        >
          Critiques ({vulns?.summary.critical_count ?? 0})
        </button>
        <button
          onClick={() => setSeverityFilter('warning')}
          className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${
            severityFilter === 'warning'
              ? 'bg-amber-600 text-white'
              : 'bg-white text-[#3D5166] border border-[#E0E4E9] hover:bg-[#F4F6F8]'
          }`}
        >
          Avertissements ({vulns?.summary.warning_count ?? 0})
        </button>
      </div>

      {/* ── Findings List ── */}
      <div className="bg-white border border-[#E0E4E9] rounded-2xl p-6 shadow-xs space-y-4">
        <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-3">
          <h2 className="text-sm font-bold text-[#0C1825]">Alertes Actives du Scan Réseau</h2>
          <span className="text-xs text-[#3D5166]">
            Dernier scan : {formatDate(vulns?.last_scanned_at)}
          </span>
        </div>

        {filteredFindings.length === 0 ? (
          <div className="py-12 text-center text-xs text-[#3D5166]">
            Aucune alerte pour ce niveau de sévérité.
          </div>
        ) : (
          <div className="space-y-3">
            {filteredFindings.map((f, idx) => (
              <div
                key={idx}
                className="p-4 rounded-xl border border-[#E0E4E9] bg-[#F8FAFC] space-y-2 text-xs"
              >
                <div className="flex items-center justify-between">
                  <span
                    className={`px-2.5 py-0.5 rounded text-[10px] font-bold uppercase ${
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
                <div className="text-sm font-bold text-[#0C1825]">{f.title}</div>
                <div className="text-[#3D5166]">{f.description}</div>
                <div className="text-emerald-700 font-medium pt-1 border-t border-[#E0E4E9]">
                  <span className="text-[#3D5166]">Recommandation : </span>
                  {f.recommendation}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* ── Suspended Users Alert Section ── */}
      {suspendedCustomers.length > 0 && (
        <div className="bg-white border border-[#E0E4E9] rounded-2xl p-6 shadow-xs space-y-4">
          <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-3">
            <div className="flex items-center gap-2">
              <UserX className="size-4 text-amber-600" />
              <h2 className="text-sm font-bold text-[#0C1825]">Comptes Clients en Suspension Préventive</h2>
            </div>
            <Link
              href="/banned-users"
              className="text-xs font-semibold text-[#C0272D] hover:underline flex items-center gap-1"
            >
              <span>Gérer les blocages</span>
              <ArrowRight className="size-3.5" />
            </Link>
          </div>

          <div className="space-y-2">
            {suspendedCustomers.map((u) => (
              <div
                key={u.id}
                className="p-3 rounded-xl bg-[#F8FAFC] border border-[#E0E4E9] flex items-center justify-between text-xs"
              >
                <div>
                  <span className="font-bold text-[#0C1825]">{u.first_name} {u.last_name}</span>{' '}
                  <span className="text-[#3D5166]">({u.email})</span>
                  <div className="text-[11px] text-red-600 mt-0.5">
                    Motif : {u.banned_reason || 'Activité suspecte'}
                  </div>
                </div>
                <div className="text-[11px] text-[#3D5166]">
                  {formatDate(u.banned_at)}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
