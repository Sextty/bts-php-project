'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import {
  BadgeDollarSign,
  Building2,
  CheckCircle2,
  Clock3,
  Download,
  RefreshCw,
  ShieldCheck,
  Workflow,
  XCircle,
} from 'lucide-react';
import { BarList, TrendChart } from '@/components/charts/trend-chart';
import { ErrorAlert } from '@/components/error-alert';
import { PageLoading } from '@/components/page-loading';
import {
  downloadAnalyticsCsv,
  getAnalytics,
  getAnalyticsDataQuality,
  type AnalyticsDataQuality,
  type AnalyticsSnapshot,
} from '@/lib/api/analytics';
import { ApiError } from '@/lib/api/client';
import { getStaffToken } from '@/lib/auth/staff-token';
import { saveBlob } from '@/lib/download';
import { statusLabel } from '@/lib/status-labels';

const PERIODS = [
  { value: 30, label: '30 jours' },
  { value: 90, label: '90 jours' },
  { value: 365, label: '1 an' },
  { value: 730, label: '2 ans' },
] as const;

type ExportDataset = 'timeline' | 'statuses' | 'branches' | 'appointments' | 'workflow' | 'credit' | 'geography';

export default function AdminAnalyticsPage() {
  const router = useRouter();
  const [data, setData] = useState<AnalyticsSnapshot | null>(null);
  const [quality, setQuality] = useState<AnalyticsDataQuality | null>(null);
  const [period, setPeriod] = useState(365);
  const [dataset, setDataset] = useState<ExportDataset>('timeline');
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (days: number, refresh = false) => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    if (refresh) setRefreshing(true);
    setError(null);
    try {
      const [analyticsResult, qualityResult] = await Promise.allSettled([getAnalytics(days), getAnalyticsDataQuality()]);
      if (analyticsResult.status === 'fulfilled') setData(analyticsResult.value);
      else throw analyticsResult.reason;
      if (qualityResult.status === 'fulfilled') setQuality(qualityResult.value);
      else setQuality(null);
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Impossible de charger les analytics.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [router]);

  useEffect(() => {
    queueMicrotask(() => void load(period));
  }, [load, period]);

  async function exportCsv() {
    setExporting(true);
    setError(null);
    try {
      const blob = await downloadAnalyticsCsv(period, dataset);
      saveBlob(blob, `bts-analytics-${dataset}.csv`);
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : "L'export est indisponible.");
    } finally {
      setExporting(false);
    }
  }

  if (loading) return <PageLoading />;

  return (
    <div className="admin-page space-y-7">
      <section className="admin-page-hero flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
        <div className="space-y-1.5">
          <p className="overline">Pilotage décisionnel</p>
          <h1 className="text-3xl font-bold tracking-tight text-[#0C1825]">Analytics crédit & réseau</h1>
          <p className="max-w-3xl text-sm text-[#3D5166]">
            Agrégats sécurisés issus de MariaDB. Aucun document, OTP, token ou détail client n&apos;est transmis.
          </p>
        </div>
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <label className="sr-only" htmlFor="analytics-period">Période</label>
          <select id="analytics-period" value={period} onChange={(event) => setPeriod(Number(event.target.value))} className="min-h-10 rounded-xl border border-[#E0E4E9] bg-white px-3 text-sm font-semibold text-[#0C1825] focus:outline-none focus:ring-2 focus:ring-[#C0272D]/25">
            {PERIODS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
          <label className="sr-only" htmlFor="analytics-export">Jeu à exporter</label>
          <select id="analytics-export" value={dataset} onChange={(event) => setDataset(event.target.value as ExportDataset)} className="min-h-10 rounded-xl border border-[#E0E4E9] bg-white px-3 text-sm text-[#0C1825] focus:outline-none focus:ring-2 focus:ring-[#C0272D]/25">
            <option value="timeline">Tendance</option><option value="statuses">Statuts</option><option value="branches">Agences</option><option value="appointments">Rendez-vous</option><option value="workflow">Workflow</option><option value="credit">Crédit</option><option value="geography">Géographie</option>
          </select>
          <button type="button" onClick={exportCsv} disabled={exporting || !data} className="btn-outline min-h-10 px-3 text-xs focus-visible:ring-2 focus-visible:ring-[#C0272D]/30">
            <Download className="size-4" /> {exporting ? 'Export…' : 'CSV'}
          </button>
          <button type="button" onClick={() => load(period, true)} disabled={refreshing} aria-label="Rafraîchir les analytics" className="flex size-10 items-center justify-center rounded-xl border border-[#E0E4E9] bg-white text-[#3D5166] transition hover:border-[#C0272D]/30 hover:text-[#C0272D] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C0272D]/30">
            <RefreshCw className={`size-4 ${refreshing ? 'animate-spin' : ''}`} />
          </button>
        </div>
      </section>

      <ErrorAlert message={error} />
      {!data ? null : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Kpi icon={Building2} label="Demandes" value={formatNumber(data.kpis.total_applications)} hint={`${formatNumber(data.kpis.pending)} en cours`} />
            <Kpi icon={CheckCircle2} label="Taux d’approbation" value={data.kpis.approval_rate === null ? '—' : `${data.kpis.approval_rate}%`} hint={`${formatNumber(data.kpis.approved)} approuvées`} tone="success" />
            <Kpi icon={BadgeDollarSign} label="Montant sollicité" value={formatTnd(data.kpis.requested_amount_total)} hint={`Moyenne ${formatTnd(data.kpis.requested_amount_average)}`} />
            <Kpi icon={Clock3} label="Traitement moyen" value={data.kpis.average_processing_hours === null ? '—' : `${data.kpis.average_processing_hours} h`} hint="Soumission → première décision" />
          </div>

          <div className="grid gap-5 xl:grid-cols-[1.6fr_1fr]">
            <Panel title="Flux des demandes" subtitle={`${data.meta.from} → ${data.meta.to}`}>
              <TrendChart labels={data.timeline.map((point) => shortDate(point.date))} series={[
                { key: 'created', label: 'Créées', colorVar: '--viz-series-1', values: data.timeline.map((point) => point.created) },
                { key: 'approved', label: 'Approuvées', colorVar: '--viz-series-3', values: data.timeline.map((point) => point.approved) },
                { key: 'rejected', label: 'Rejetées', colorVar: '--viz-series-2', values: data.timeline.map((point) => point.rejected) },
              ]} />
            </Panel>
            <Panel title="Portefeuille par statut" subtitle="Cycle métier réel">
              <BarList items={data.statuses.filter((item) => item.count > 0).map((item) => ({ label: statusLabel(item.status), value: item.count, hint: item.status }))} />
            </Panel>
          </div>

          <div className="grid gap-5 lg:grid-cols-3">
            <Panel title="Workflow" subtitle="Entrées et progression">
              <BarList items={data.workflow.stages.map((stage) => ({ label: workflowLabel(stage.stage), value: stage.count, hint: stage.completion_rate === null ? undefined : `${stage.completion_rate}%` }))} />
              <dl className="mt-5 grid gap-2 border-t border-slate-100 pt-4 text-xs">
                <Duration label="Création → soumission" value={data.workflow.average_hours.creation_to_submission} />
                <Duration label="Soumission → décision" value={data.workflow.average_hours.submission_to_first_decision} />
                <Duration label="Décision → rendez-vous" value={data.workflow.average_hours.decision_to_first_appointment} />
              </dl>
            </Panel>
            <Panel title="Rendez-vous" subtitle="Capacité sur la période">
              <div className="grid grid-cols-2 gap-3">
                <MiniStat label="Total" value={data.appointments.total} /><MiniStat label="Confirmés" value={data.appointments.accepted} />
                <MiniStat label="Occupation" value={data.appointments.utilization_rate === null ? '—' : `${data.appointments.utilization_rate}%`} /><MiniStat label="30 jours à venir" value={data.appointments.next_30_days_active} />
              </div>
              <p className="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-[#3D5166]">{formatNumber(data.appointments.occupied_slots)} créneaux actifs sur une capacité théorique de {formatNumber(data.appointments.active_capacity)}.</p>
            </Panel>
            <Panel title="Composition du financement" subtitle="Champs financiers existants">
              <BarList items={Object.entries(data.credit.financing_composition).map(([label, value]) => ({ label, value: Math.round(value) }))} />
              <p className="mt-4 text-xs text-[#3D5166]">EPR : {formatNumber(data.credit.epr_document_count)} document(s). Aucun montant accordé n&apos;est inventé.</p>
            </Panel>
          </div>

          <Panel title="Comparaison des agences" subtitle="Charge, décisions et rendez-vous — données agrégées uniquement">
            <div className="overflow-x-auto rounded-xl border border-slate-200">
              <table className="min-w-full divide-y divide-slate-200 text-left text-xs">
                <thead className="bg-slate-50 font-semibold uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-3">Agence</th><th className="px-4 py-3 text-right">Demandes</th><th className="px-4 py-3 text-right">En attente</th><th className="px-4 py-3 text-right">Approuvées</th><th className="px-4 py-3 text-right">Rejetées</th><th className="px-4 py-3 text-right">Délai moyen</th><th className="px-4 py-3 text-right">RDV</th></tr></thead>
                <tbody className="divide-y divide-slate-100 bg-white">{data.branches.map((branch) => <tr key={branch.branch_id} className="transition-colors hover:bg-slate-50"><td className="px-4 py-3"><p className="font-semibold text-[#0C1825]">{branch.name}</p><p className="text-[11px] text-[#3D5166]">{branch.governorate}{branch.delegation ? ` · ${branch.delegation}` : ''}</p></td><td className="px-4 py-3 text-right font-mono">{branch.application_volume}</td><td className="px-4 py-3 text-right font-mono text-amber-700">{branch.pending_workload}</td><td className="px-4 py-3 text-right font-mono text-emerald-700">{branch.approved}</td><td className="px-4 py-3 text-right font-mono text-rose-700">{branch.rejected}</td><td className="px-4 py-3 text-right font-mono">{branch.average_processing_hours === null ? '—' : `${branch.average_processing_hours}h`}</td><td className="px-4 py-3 text-right font-mono">{branch.appointment_volume}</td></tr>)}</tbody>
              </table>
            </div>
          </Panel>

          <div className="grid gap-5 lg:grid-cols-2">
            <Panel title="Répartition géographique" subtitle="Localisation déclarée du projet"><BarList items={data.geography.governorates.slice(0, 12).map((item) => ({ label: item.label, value: item.count }))} /></Panel>
            <Panel title="Qualité analytique" subtitle="Contrôles non destructifs">
              <div className={`flex items-start gap-3 rounded-xl border p-4 ${quality?.passed ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-900'}`}>
                {quality?.passed ? <ShieldCheck className="mt-0.5 size-5 shrink-0" /> : <XCircle className="mt-0.5 size-5 shrink-0" />}
                <div><p className="font-semibold">{quality?.passed ? 'Données cohérentes' : 'Anomalies à examiner'}</p><p className="mt-1 text-xs">{quality ? `${quality.total_violations} violation(s) sur ${Object.keys(quality.checks).length} contrôles.` : 'Contrôle réservé aux administrateurs.'}</p></div>
              </div>
            </Panel>
          </div>
        </>
      )}
    </div>
  );
}

function Panel({ title, subtitle, children }: { title: string; subtitle: string; children: React.ReactNode }) { return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><header className="mb-5"><h2 className="text-base font-bold text-[#0C1825]">{title}</h2><p className="mt-0.5 text-xs text-[#3D5166]">{subtitle}</p></header>{children}</section>; }
function Kpi({ icon: Icon, label, value, hint, tone = 'default' }: { icon: typeof Workflow; label: string; value: string; hint: string; tone?: 'default' | 'success' }) { return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div className="flex items-start justify-between gap-3"><div><p className="text-xs font-semibold uppercase tracking-wide text-[#3D5166]">{label}</p><p className="mt-2 text-2xl font-bold tracking-tight text-[#0C1825]">{value}</p><p className="mt-1 text-xs text-[#3D5166]">{hint}</p></div><span className={`flex size-10 items-center justify-center rounded-xl ${tone === 'success' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-[#C0272D]'}`}><Icon className="size-5" /></span></div></section>; }
function MiniStat({ label, value }: { label: string; value: number | string }) { return <div className="rounded-xl border border-slate-100 bg-slate-50 p-3"><p className="text-[11px] font-medium text-[#3D5166]">{label}</p><p className="mt-1 text-lg font-bold text-[#0C1825]">{value}</p></div>; }
function Duration({ label, value }: { label: string; value: number | null }) { return <div className="flex items-center justify-between gap-3"><dt className="text-[#3D5166]">{label}</dt><dd className="font-mono font-semibold text-[#0C1825]">{value === null ? '—' : `${value} h`}</dd></div>; }
function formatNumber(value: number) { return new Intl.NumberFormat('fr-TN').format(value); }
function formatTnd(value: number | null) { return value === null ? '—' : `${new Intl.NumberFormat('fr-TN', { maximumFractionDigits: 0 }).format(value)} TND`; }
function shortDate(value: string) { return new Date(`${value}T00:00:00`).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }); }
function workflowLabel(stage: string) { return ({ created: 'Créées', submitted: 'Soumises', staff_reviewed: 'Revue agence', admin_reviewed: 'Décision finale', appointment: 'Rendez-vous' } as Record<string, string>)[stage] ?? stage; }
