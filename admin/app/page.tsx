'use client';

import { useEffect, useState, useCallback } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  ChevronRight,
  Inbox,
  RefreshCw,
  TrendingUp,
  TrendingDown,
  Users,
  Clock,
  CheckCircle2,
  XCircle,
  FolderOpen,
  Percent,
  Activity,
  Globe,
  Shield,
  Zap,
} from 'lucide-react';
import { ErrorAlert } from '@/components/error-alert';
import { TrendChart, BarList } from '@/components/charts/trend-chart';
import { InlineLoading, PageLoading } from '@/components/page-loading';
import { listStaffApplications, type StaffApplicationDto } from '@/lib/api/staff';
import { getDashboard, getTraffic, type DashboardDto, type TrafficDto } from '@/lib/api/staff-insights';
import { ApiError } from '@/lib/api/client';
import { getStaffToken } from '@/lib/auth/staff-token';
import { statusLabel, statusColor } from '@/lib/status-labels';

const PERIOD_OPTIONS = [
  { value: 7, label: '7j' },
  { value: 14, label: '14j' },
  { value: 30, label: '30j' },
  { value: 90, label: '90j' },
] as const;

function shortDate(iso: string): string {
  const d = new Date(iso);
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
}

export default function AdminDashboardPage() {
  const router = useRouter();
  const [dashboard, setDashboard] = useState<DashboardDto | null>(null);
  const [traffic, setTraffic] = useState<TrafficDto | null>(null);
  const [queue, setQueue] = useState<StaffApplicationDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [period, setPeriod] = useState(30);

  const fetchData = useCallback(async (days: number, isRefresh = false) => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    if (isRefresh) setRefreshing(true);
    try {
      const [dashRes, trafficRes, queueRes] = await Promise.allSettled([
        getDashboard(days),
        getTraffic(days),
        listStaffApplications('STAFF_APPROVED'),
      ]);

      if (dashRes.status === 'fulfilled') setDashboard(dashRes.value);
      else if (dashRes.reason instanceof ApiError && dashRes.reason.status === 403) {
        router.replace('/login');
        return;
      } else {
        setError(dashRes.reason instanceof ApiError ? dashRes.reason.message : 'Erreur de chargement.');
      }

      if (trafficRes.status === 'fulfilled') setTraffic(trafficRes.value);
      if (queueRes.status === 'fulfilled') setQueue(queueRes.value.applications);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [router]);

  useEffect(() => {
    fetchData(period);
  }, [fetchData, period]);

  if (loading) return <PageLoading />;

  return (
    <div className="px-4 sm:px-8 py-8 max-w-7xl mx-auto space-y-8">
      {/* ── Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-[#0C1825] tracking-tight">Tableau de bord</h1>
          <p className="text-sm text-[#3D5166] mt-0.5">Vue d'ensemble du portefeuille de dossiers de crédit.</p>
        </div>

        <div className="flex items-center gap-3">
          {/* Period selector */}
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
            onClick={() => fetchData(period, true)}
            disabled={refreshing}
            className="size-9 rounded-xl bg-white border border-[#E0E4E9] shadow-xs flex items-center justify-center text-[#3D5166] hover:text-[#C0272D] hover:border-[#C0272D]/30 transition-all"
            title="Rafraîchir"
          >
            <RefreshCw className={`size-4 ${refreshing ? 'animate-spin' : ''}`} />
          </button>
        </div>
      </div>

      <ErrorAlert message={error} />

      {dashboard && (
        <div className="space-y-6">
          {/* ── KPI Cards ── */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <KpiCard
              label="Total Dossiers"
              value={dashboard.kpis.total}
              icon={FolderOpen}
              color="text-[#0C1825]"
              bgColor="bg-[#F4F6F8]"
            />
            <KpiCard
              label="En attente (conseiller)"
              value={dashboard.kpis.awaiting_staff}
              icon={Clock}
              color="text-amber-600"
              bgColor="bg-amber-50"
              emphasis={dashboard.kpis.awaiting_staff > 0}
            />
            <KpiCard
              label="En attente (vous)"
              value={dashboard.kpis.awaiting_admin}
              icon={Shield}
              color="text-[#C0272D]"
              bgColor="bg-[#FDF2F2]"
              emphasis={dashboard.kpis.awaiting_admin > 0}
            />
            <KpiCard
              label="Taux d'approbation"
              value={dashboard.kpis.approval_rate === null ? '—' : `${dashboard.kpis.approval_rate}%`}
              icon={Percent}
              color="text-emerald-600"
              bgColor="bg-emerald-50"
              hint="Des dossiers décidés"
            />
            <KpiCard
              label="Approuvés"
              value={dashboard.kpis.approved}
              icon={CheckCircle2}
              color="text-emerald-600"
              bgColor="bg-emerald-50"
            />
            <KpiCard
              label="Rejetés"
              value={dashboard.kpis.rejected}
              icon={XCircle}
              color="text-red-600"
              bgColor="bg-red-50"
            />
            <KpiCard
              label="En cours (client)"
              value={dashboard.kpis.in_progress}
              icon={Users}
              color="text-blue-600"
              bgColor="bg-blue-50"
              hint="Formulaire en cours"
            />
            <KpiCard
              label="Délai moyen"
              value={dashboard.kpis.avg_decision_hours === null ? '—' : `${dashboard.kpis.avg_decision_hours}h`}
              icon={TrendingUp}
              color="text-purple-600"
              bgColor="bg-purple-50"
              hint="Soumission → décision"
            />
          </div>

          {/* ── Chart + Pipeline ── */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="lg:col-span-2 bg-white rounded-2xl border border-[#E0E4E9] shadow-xs p-6">
              <div className="flex items-center justify-between mb-4">
                <div>
                  <h3 className="text-sm font-bold text-[#0C1825]">Activité sur {period} jours</h3>
                  <p className="text-[11px] text-[#3D5166] mt-0.5">Dossiers créés, approuvés et rejetés</p>
                </div>
              </div>
              <TrendChart
                labels={dashboard.timeline.map((t) => shortDate(t.date))}
                series={[
                  { key: 'created', label: 'Créés', colorVar: '--viz-series-1', values: dashboard.timeline.map((t) => t.created) },
                  { key: 'approved', label: 'Approuvés', colorVar: '--viz-series-3', values: dashboard.timeline.map((t) => t.approved) },
                  { key: 'rejected', label: 'Rejetés', colorVar: '--viz-series-2', values: dashboard.timeline.map((t) => t.rejected) },
                ]}
              />
            </div>

            <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs p-6">
              <h3 className="text-sm font-bold text-[#0C1825] mb-4">Pipeline</h3>
              <BarList
                items={dashboard.pipeline.map((p) => ({ label: statusLabel(p.status), value: p.count, hint: p.status }))}
                emptyLabel="Aucun dossier pour le moment."
              />
            </div>
          </div>

          {/* ── Traffic + Team ── */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* Traffic Widget */}
            {traffic && (
              <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs p-6 space-y-4">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-bold text-[#0C1825] flex items-center gap-2">
                    <Activity className="size-4 text-[#C0272D]" />
                    Trafic & Activité
                  </h3>
                  <span className="text-[10px] font-mono font-bold text-[#C0272D] bg-[#FDF2F2] px-2 py-0.5 rounded border border-[#FECACA]">
                    {traffic.total_events} événements
                  </span>
                </div>

                <div className="grid grid-cols-3 gap-3">
                  <div className="bg-[#F4F6F8] rounded-xl p-3 text-center">
                    <Users className="size-4 text-blue-600 mx-auto mb-1" />
                    <p className="text-lg font-bold text-[#0C1825]">{traffic.active_customers}</p>
                    <p className="text-[10px] text-[#3D5166]">Clients actifs</p>
                  </div>
                  <div className="bg-[#F4F6F8] rounded-xl p-3 text-center">
                    <Shield className="size-4 text-emerald-600 mx-auto mb-1" />
                    <p className="text-lg font-bold text-[#0C1825]">{traffic.active_staff}</p>
                    <p className="text-[10px] text-[#3D5166]">Staff actifs</p>
                  </div>
                  <div className="bg-[#F4F6F8] rounded-xl p-3 text-center">
                    <Globe className="size-4 text-purple-600 mx-auto mb-1" />
                    <p className="text-lg font-bold text-[#0C1825]">{traffic.unique_ips ?? '—'}</p>
                    <p className="text-[10px] text-[#3D5166]">IPs uniques</p>
                  </div>
                </div>

                {traffic.top_actions.length > 0 && (
                  <div>
                    <h4 className="text-[11px] font-bold uppercase tracking-wider text-[#3D5166] mb-2">Actions fréquentes</h4>
                    <div className="space-y-1.5">
                      {traffic.top_actions.slice(0, 5).map((a) => (
                        <div key={a.action} className="flex items-center justify-between text-xs">
                          <span className="text-[#0C1825] font-medium truncate">{a.action.replace(/_/g, ' ')}</span>
                          <span className="text-[#3D5166] font-mono">{a.count}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            )}

            {/* Team Activity */}
            <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs p-6">
              <h3 className="text-sm font-bold text-[#0C1825] mb-4 flex items-center gap-2">
                <Users className="size-4 text-[#C0272D]" />
                Activité de l'Équipe
              </h3>
              {dashboard.team.length === 0 ? (
                <p className="py-6 text-center text-sm text-[#3D5166]">Aucune décision enregistrée.</p>
              ) : (
                <ul className="space-y-3">
                  {dashboard.team.map((m) => (
                    <li key={m.staff_user_id} className="flex items-center justify-between gap-3 p-3 rounded-xl bg-[#F4F6F8]">
                      <div className="flex items-center gap-3 min-w-0">
                        <div className="size-9 rounded-full bg-[#C0272D]/10 text-[#C0272D] flex items-center justify-center text-xs font-bold shrink-0">
                          {m.name.split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase()}
                        </div>
                        <div className="min-w-0">
                          <p className="text-sm font-semibold text-[#0C1825] truncate">{m.name}</p>
                          {m.role && (
                            <span className="text-[10px] font-bold uppercase text-[#3D5166]">{m.role === 'admin' ? 'Administrateur' : 'Conseiller'}</span>
                          )}
                        </div>
                      </div>
                      <div className="flex items-center gap-3 shrink-0">
                        <span className="flex items-center gap-1 text-xs">
                          <CheckCircle2 className="size-3 text-emerald-500" />
                          <span className="font-bold text-[#0C1825]">{m.approvals}</span>
                        </span>
                        <span className="flex items-center gap-1 text-xs">
                          <XCircle className="size-3 text-red-500" />
                          <span className="font-bold text-[#0C1825]">{m.rejections}</span>
                        </span>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>

          {/* ── Awaiting Decision Queue ── */}
          <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs overflow-hidden">
            <div className="flex items-center justify-between px-6 py-4 border-b border-[#E0E4E9]">
              <div>
                <h3 className="text-sm font-bold text-[#0C1825] flex items-center gap-2">
                  <Zap className="size-4 text-[#C0272D]" />
                  En attente de votre décision finale
                </h3>
                <p className="text-[11px] text-[#3D5166] mt-0.5">Dossiers approuvés par le conseiller, en attente de validation administrative</p>
              </div>
              {queue.length > 0 && (
                <span className="text-[10px] font-mono font-bold text-[#C0272D] bg-[#FDF2F2] px-2.5 py-1 rounded-lg border border-[#FECACA]">
                  {queue.length} dossier{queue.length > 1 ? 's' : ''}
                </span>
              )}
            </div>

            {queue.length === 0 ? (
              <div className="flex flex-col items-center gap-3 py-12 text-center">
                <div className="size-12 rounded-full bg-[#F4F6F8] flex items-center justify-center">
                  <Inbox className="size-6 text-[#E0E4E9]" />
                </div>
                <p className="text-sm text-[#3D5166]">Aucun dossier en attente de décision finale.</p>
              </div>
            ) : (
              <div className="divide-y divide-[#F4F6F8]">
                {queue.map((app) => (
                  <Link
                    key={app.id}
                    href={`/applications/${app.id}`}
                    className="flex items-center justify-between gap-4 px-6 py-4 hover:bg-[#FDF2F2]/30 transition-colors group"
                  >
                    <div className="flex items-center gap-4 min-w-0">
                      <div className="size-10 rounded-full bg-[#C0272D]/10 text-[#C0272D] flex items-center justify-center text-xs font-bold shrink-0">
                        {(app.applicant?.name ?? '??').split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase()}
                      </div>
                      <div className="min-w-0">
                        <p className="text-sm font-semibold text-[#0C1825] truncate">{app.applicant?.name ?? '—'}</p>
                        <div className="flex items-center gap-2 mt-0.5">
                          <span className="text-[11px] text-[#3D5166] font-mono">{app.credit_request?.n_demande ?? `#${app.id}`}</span>
                          {app.credit_request?.montant_global_sollicite && (
                            <>
                              <span className="text-[#E0E4E9]">·</span>
                              <span className="text-[11px] font-bold text-[#0C1825]">
                                {Number(app.credit_request.montant_global_sollicite).toLocaleString('fr-FR')} {app.credit_request.code_devise}
                              </span>
                            </>
                          )}
                        </div>
                      </div>
                    </div>
                    <div className="flex items-center gap-3 shrink-0">
                      {app.submitted_at && (
                        <span className="text-[11px] text-[#3D5166]">
                          {new Date(app.submitted_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })}
                        </span>
                      )}
                      <ChevronRight className="size-4 text-[#E0E4E9] group-hover:text-[#C0272D] transition-colors" />
                    </div>
                  </Link>
                ))}
              </div>
            )}

            {queue.length > 0 && (
              <div className="px-6 py-3 border-t border-[#E0E4E9] bg-[#F4F6F8]/50">
                <Link href="/applications?status=STAFF_APPROVED" className="text-xs font-semibold text-[#C0272D] hover:underline">
                  Voir tous les dossiers →
                </Link>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function KpiCard({
  label,
  value,
  icon: Icon,
  color,
  bgColor,
  hint,
  emphasis = false,
}: {
  label: string;
  value: number | string;
  icon: React.ComponentType<{ className?: string }>;
  color: string;
  bgColor: string;
  hint?: string;
  emphasis?: boolean;
}) {
  return (
    <div className={`bg-white rounded-2xl border border-[#E0E4E9] shadow-xs p-5 relative overflow-hidden ${emphasis ? 'ring-1 ring-[#C0272D]/20' : ''}`}>
      {emphasis && <div className="absolute top-0 left-0 right-0 h-1 bg-[#C0272D]" />}
      <div className="flex items-start justify-between">
        <div className="space-y-1">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-[#3D5166]">{label}</p>
          <p className={`text-2xl font-bold ${emphasis ? 'text-[#C0272D]' : 'text-[#0C1825]'}`}>{value}</p>
          {hint && <p className="text-[10px] text-[#3D5166]">{hint}</p>}
        </div>
        <div className={`size-9 rounded-xl ${bgColor} ${color} flex items-center justify-center shrink-0`}>
          <Icon className="size-[18px]" />
        </div>
      </div>
    </div>
  );
}
