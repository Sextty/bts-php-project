'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { BadgeDollarSign, CheckCircle2, Clock3, Download, FolderKanban, RefreshCw } from 'lucide-react';
import { BarList, TrendChart } from '@/components/charts/trend-chart';
import { ErrorAlert } from '@/components/error-alert';
import { PageLoading } from '@/components/page-loading';
import { StaffHeader } from '@/components/staff-header';
import { downloadAnalyticsCsv, getAnalytics, type AnalyticsSnapshot } from '@/lib/api/analytics';
import { ApiError } from '@/lib/api/client';
import { getStaffRole, getStaffToken } from '@/lib/auth/staff-token';
import { saveBlob } from '@/lib/download';
import { statusLabel } from '@/lib/status-labels';

const PERIODS = [30, 90, 365, 730] as const;
type ExportDataset = 'timeline' | 'statuses' | 'branches' | 'appointments' | 'workflow' | 'credit' | 'geography';

export default function StaffAnalyticsPage() {
  const router = useRouter();
  const [data, setData] = useState<AnalyticsSnapshot | null>(null);
  const [period, setPeriod] = useState(365);
  const [dataset, setDataset] = useState<ExportDataset>('timeline');
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const role = getStaffRole() ?? 'staff';

  const load = useCallback(async (days: number, refresh = false) => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    if (refresh) setRefreshing(true);
    setError(null);
    try {
      setData(await getAnalytics(days));
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Impossible de charger les analytics de l’agence.');
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
      saveBlob(await downloadAnalyticsCsv(period, dataset), `bts-agence-${dataset}.csv`);
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : "L’export est indisponible.");
    } finally {
      setExporting(false);
    }
  }

  if (loading) return <PageLoading />;

  return (
    <div className="staff-page text-[#1E2D3D]">
      <StaffHeader role={role} />
      <main id="main" className="staff-main space-y-6">
        <section className="staff-page-hero flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
          <div className="space-y-1">
            <p className="overline">Pilotage de l’agence</p>
            <h1 className="font-display text-3xl font-light text-[#0C1825]">Analytics opérationnels</h1>
            <p className="max-w-2xl text-xs text-[#3D5166]">Indicateurs agrégés limités à votre agence, sans données personnelles ni documents.</p>
          </div>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <label className="sr-only" htmlFor="analytics-period">Période</label>
            <select id="analytics-period" value={period} onChange={(event) => setPeriod(Number(event.target.value))} className="min-h-10 rounded-xl border border-[#E0E4E9] bg-white px-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-[#C0272D]/25">
              {PERIODS.map((days) => <option key={days} value={days}>{days === 365 ? '1 an' : days === 730 ? '2 ans' : `${days} jours`}</option>)}
            </select>
            <label className="sr-only" htmlFor="analytics-export">Jeu à exporter</label>
            <select id="analytics-export" value={dataset} onChange={(event) => setDataset(event.target.value as ExportDataset)} className="min-h-10 rounded-xl border border-[#E0E4E9] bg-white px-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#C0272D]/25">
              <option value="timeline">Tendance</option><option value="statuses">Statuts</option><option value="appointments">Rendez-vous</option><option value="workflow">Workflow</option><option value="credit">Crédit</option><option value="geography">Géographie</option>
            </select>
            <button type="button" onClick={exportCsv} disabled={exporting || !data} className="btn-outline min-h-10 px-3 text-xs"><Download className="size-4" />{exporting ? 'Export…' : 'CSV'}</button>
            <button type="button" onClick={() => load(period, true)} disabled={refreshing} aria-label="Rafraîchir les analytics" className="flex size-10 items-center justify-center rounded-xl border border-[#E0E4E9] bg-white text-[#3D5166] transition hover:text-[#C0272D] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C0272D]/30"><RefreshCw className={`size-4 ${refreshing ? 'animate-spin' : ''}`} /></button>
          </div>
        </section>

        <ErrorAlert message={error} />
        {!data ? null : <>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Kpi icon={FolderKanban} label="Demandes" value={formatNumber(data.kpis.total_applications)} hint={`${formatNumber(data.kpis.pending)} à traiter`} />
            <Kpi icon={CheckCircle2} label="Approbation" value={data.kpis.approval_rate === null ? '—' : `${data.kpis.approval_rate}%`} hint={`${formatNumber(data.kpis.approved)} approuvées`} tone="success" />
            <Kpi icon={BadgeDollarSign} label="Montant sollicité" value={formatTnd(data.kpis.requested_amount_total)} hint={`Moyenne ${formatTnd(data.kpis.requested_amount_average)}`} />
            <Kpi icon={Clock3} label="Délai moyen" value={data.kpis.average_processing_hours === null ? '—' : `${data.kpis.average_processing_hours} h`} hint="Soumission → décision" />
          </div>

          <div className="grid gap-5 xl:grid-cols-[1.6fr_1fr]">
            <Panel title="Flux des demandes" subtitle={`${data.meta.from} → ${data.meta.to}`}>
              <TrendChart labels={data.timeline.map((point) => shortDate(point.date))} series={[
                { key: 'created', label: 'Créées', colorVar: '--viz-series-1', values: data.timeline.map((point) => point.created) },
                { key: 'approved', label: 'Approuvées', colorVar: '--viz-series-3', values: data.timeline.map((point) => point.approved) },
                { key: 'rejected', label: 'Rejetées', colorVar: '--viz-series-2', values: data.timeline.map((point) => point.rejected) },
              ]} />
            </Panel>
            <Panel title="Portefeuille par statut" subtitle="Cycle métier réel"><BarList items={data.statuses.filter((item) => item.count > 0).map((item) => ({ label: statusLabel(item.status), value: item.count }))} /></Panel>
          </div>

          <div className="grid gap-5 lg:grid-cols-3">
            <Panel title="Progression workflow" subtitle="Étapes atteintes"><BarList items={data.workflow.stages.map((stage) => ({ label: workflowLabel(stage.stage), value: stage.count, hint: stage.completion_rate === null ? undefined : `${stage.completion_rate}%` }))} /></Panel>
            <Panel title="Rendez-vous" subtitle="Capacité de votre agence"><div className="grid grid-cols-2 gap-3"><MiniStat label="Total" value={data.appointments.total} /><MiniStat label="Confirmés" value={data.appointments.accepted} /><MiniStat label="Occupation" value={data.appointments.utilization_rate === null ? '—' : `${data.appointments.utilization_rate}%`} /><MiniStat label="30 jours" value={data.appointments.next_30_days_active} /></div></Panel>
            <Panel title="Financement demandé" subtitle="Répartition EQP / FDR / AMG / CHP"><BarList items={Object.entries(data.credit.financing_composition).map(([label, value]) => ({ label, value: Math.round(value) }))} /><p className="mt-4 text-xs text-[#3D5166]">Montants sollicités uniquement ; aucun montant accordé n’est extrapolé.</p></Panel>
          </div>

          <div className="grid gap-5 lg:grid-cols-2">
            <Panel title="Localisation des projets" subtitle="Principaux gouvernorats"><BarList items={data.geography.governorates.slice(0, 10).map((item) => ({ label: item.label, value: item.count }))} /></Panel>
            <Panel title="Durées du parcours" subtitle="Moyennes sur la période"><dl className="grid gap-3"><Duration label="Création → soumission" value={data.workflow.average_hours.creation_to_submission} /><Duration label="Soumission → décision" value={data.workflow.average_hours.submission_to_first_decision} /><Duration label="Décision → rendez-vous" value={data.workflow.average_hours.decision_to_first_appointment} /></dl></Panel>
          </div>
        </>}
      </main>
    </div>
  );
}

function Panel({ title, subtitle, children }: { title: string; subtitle: string; children: React.ReactNode }) { return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><header className="mb-5"><h2 className="text-base font-bold text-[#0C1825]">{title}</h2><p className="mt-0.5 text-xs text-[#3D5166]">{subtitle}</p></header>{children}</section>; }
function Kpi({ icon: Icon, label, value, hint, tone = 'default' }: { icon: typeof FolderKanban; label: string; value: string; hint: string; tone?: 'default' | 'success' }) { return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div className="flex items-start justify-between gap-3"><div><p className="text-xs font-semibold uppercase tracking-wide text-[#3D5166]">{label}</p><p className="mt-2 text-2xl font-bold text-[#0C1825]">{value}</p><p className="mt-1 text-xs text-[#3D5166]">{hint}</p></div><span className={`flex size-10 items-center justify-center rounded-xl ${tone === 'success' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-[#C0272D]'}`}><Icon className="size-5" /></span></div></section>; }
function MiniStat({ label, value }: { label: string; value: number | string }) { return <div className="rounded-xl border border-slate-100 bg-slate-50 p-3"><p className="text-[11px] text-[#3D5166]">{label}</p><p className="mt-1 text-lg font-bold text-[#0C1825]">{value}</p></div>; }
function Duration({ label, value }: { label: string; value: number | null }) { return <div className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-3"><dt className="text-sm text-[#3D5166]">{label}</dt><dd className="font-mono text-sm font-semibold text-[#0C1825]">{value === null ? '—' : `${value} h`}</dd></div>; }
function formatNumber(value: number) { return new Intl.NumberFormat('fr-TN').format(value); }
function formatTnd(value: number | null) { return value === null ? '—' : `${new Intl.NumberFormat('fr-TN', { maximumFractionDigits: 0 }).format(value)} TND`; }
function shortDate(value: string) { return new Date(`${value}T00:00:00`).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }); }
function workflowLabel(stage: string) { return ({ created: 'Créées', submitted: 'Soumises', staff_reviewed: 'Revue agence', admin_reviewed: 'Décision finale', appointment: 'Rendez-vous' } as Record<string, string>)[stage] ?? stage; }
