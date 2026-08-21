'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { ChevronRight, FilePlus2, FileText, Calendar, Building2, ArrowRight } from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { Breadcrumbs, BackLink } from '@/components/breadcrumbs';
import { StatusBadge } from '@/components/status-badge';
import { createApplication, listApplications, type CreditApplicationDto } from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { InlineLoading } from '@/components/page-loading';
import { statusLabel, statusPhase } from '@/lib/status-labels';

function detailPath(app: CreditApplicationDto): string {
  return `/applications/${app.id}`;
}

function progressPercent(status: string): number {
  const phase = statusPhase(status);
  const map: Record<string, number> = {
    draft: 20,
    validation: 40,
    submitted: 60,
    review: 80,
    appointment: 90,
    terminal: 100,
  };
  return map[phase] ?? 10;
}

function isEditable(status: CreditApplicationDto['status']): boolean {
  return ['DRAFT', 'STEP_1_COMPLETED', 'STEP_2_COMPLETED', 'STEP_3_COMPLETED', 'READY_FOR_VALIDATION_1', 'VALIDATION_1_COMPLETED'].includes(status);
}

export default function ApplicationsPage() {
  const router = useRouter();
  const [applications, setApplications] = useState<CreditApplicationDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }
    listApplications()
      .then(({ applications }) => {
        const resolved = Array.isArray(applications) ? applications : [];
        setApplications(resolved);
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Impossible de charger vos demandes.'))
      .finally(() => setLoading(false));
  }, [router]);

  async function handleCreate() {
    setError(null);
    setCreating(true);
    try {
      const { application } = await createApplication();
      router.push(`/applications/${application.id}/client`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Impossible de créer la demande. Veuillez réessayer.');
      setCreating(false);
    }
  }

  return (
    <div className="min-h-screen bg-[#F4F6F8] text-[#1E2D3D]">
      <DashboardHeader />
      <main id="main" className="mx-auto max-w-4xl px-4 sm:px-8 py-8 sm:py-10 space-y-6">
        <BackLink href="/dashboard" label="Retour au tableau de bord" />
        <Breadcrumbs
          items={[
            { label: 'Tableau de bord', href: '/dashboard' },
            { label: 'Mes demandes' },
          ]}
          className="mb-2"
        />

        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 border-b border-[#E0E4E9] pb-4">
          <div>
            <p className="overline mb-0.5">Dossiers de Financement</p>
            <h1 className="font-display text-3xl font-light text-[#0C1825]">
              Mes demandes de crédit
            </h1>
            {!loading && (
              <p className="text-xs text-[#3D5166] mt-1">
                {applications.length === 0
                  ? 'Aucune demande enregistrée'
                  : `${applications.length} demande${applications.length > 1 ? 's' : ''} enregistrée${applications.length > 1 ? 's' : ''}`}
              </p>
            )}
          </div>
          <button
            onClick={handleCreate}
            disabled={creating}
            className="btn-red text-xs inline-flex items-center gap-2 shadow-xs shrink-0"
            style={{ padding: '8px 18px' }}
          >
            <FilePlus2 className="size-4" />
            <span>{creating ? 'Création en cours…' : 'Nouvelle demande'}</span>
          </button>
        </div>

        <ErrorAlert message={error} />

        {loading ? (
          <InlineLoading />
        ) : !error && applications.length === 0 ? (
          <div className="figma-card p-12 bg-white text-center space-y-3">
            <div className="size-14 rounded-full bg-[#F4F6F8] text-[#3D5166] mx-auto flex items-center justify-center">
              <FilePlus2 className="size-7 opacity-60" />
            </div>
            <div className="space-y-1">
              <p className="text-base font-semibold text-[#0C1825]">Vous n&apos;avez pas encore de demande de crédit</p>
              <p className="text-xs text-[#3D5166] max-w-sm mx-auto">
                Remplissez les informations de votre projet pour soumettre votre premier dossier de financement.
              </p>
            </div>
            <button
              onClick={handleCreate}
              disabled={creating}
              className="btn-red text-xs inline-flex items-center gap-2 mt-3"
            >
              <FilePlus2 className="size-3.5" />
              Commencer ma première demande
            </button>
          </div>
        ) : (
          <div className="space-y-3">
            {applications.map((app) => {
              const pct = progressPercent(app.status);
              const amountVal = app.credit_request?.montant_global_sollicite
                ? Number(app.credit_request.montant_global_sollicite)
                : 0;
              const formattedAmt =
                amountVal > 0
                  ? new Intl.NumberFormat('fr-TN', { maximumFractionDigits: 0 }).format(amountVal) +
                    ' ' +
                    (app.credit_request?.code_devise ?? 'TND')
                  : 'Montant non défini';

              return (
                <Link
                  key={app.id}
                  href={detailPath(app)}
                  className="block figma-card p-5 bg-white transition-all hover:border-[#C0272D] hover:shadow-sm group"
                >
                  <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3 mb-3">
                    <div className="min-w-0 space-y-1">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="text-sm font-bold text-[#0C1825] group-hover:text-[#C0272D] transition-colors">
                          {app.credit_request?.n_demande ?? `Dossier #${app.id}`}
                        </span>
                        {app.credit_request?.type_demande && (
                          <span className="text-[10px] font-medium text-[#3D5166] bg-[#F4F6F8] px-2 py-0.5 rounded border border-[#E0E4E9]">
                            {app.credit_request.type_demande}
                          </span>
                        )}
                      </div>

                      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-[#3D5166] pt-0.5">
                        <span className="font-semibold text-[#0C1825]">{formattedAmt}</span>
                        {app.branch?.name && (
                          <span className="flex items-center gap-1">
                            <Building2 className="size-3 text-[#C0272D]" />
                            {app.branch.name}
                          </span>
                        )}
                        <span className="flex items-center gap-1">
                          <Calendar className="size-3 text-gray-400" />
                          Créé le {new Date(app.created_at).toLocaleDateString('fr-FR')}
                        </span>
                        {app.submitted_at && (
                          <span className="text-emerald-700">
                            · Soumis le {new Date(app.submitted_at).toLocaleDateString('fr-FR')}
                          </span>
                        )}
                      </div>
                    </div>

                    <div className="flex items-center gap-2 shrink-0">
                      <StatusBadge status={app.status} label={statusLabel(app.status)} />
                      <ChevronRight className="size-4 text-gray-400 group-hover:text-[#C0272D] group-hover:translate-x-0.5 transition-all" />
                    </div>
                  </div>

                  {/* Progress Bar */}
                  <div className="space-y-1 pt-2 border-t border-[#E0E4E9]">
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-[#F4F6F8]">
                      <div
                        className="h-full rounded-full bg-[#C0272D] transition-all duration-500"
                        style={{ width: `${pct}%` }}
                      />
                    </div>
                    <div className="flex items-center justify-between text-[10px] text-[#3D5166]">
                      <span>État d&apos;avancement</span>
                      <span className="font-mono font-bold text-[#0C1825]">{pct}%</span>
                    </div>
                  </div>
                </Link>
              );
            })}
          </div>
        )}
      </main>
    </div>
  );
}
