'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { Building2, Calendar, ChevronRight, FilePlus2, Trash2, TriangleAlert } from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { Breadcrumbs, BackLink } from '@/components/breadcrumbs';
import { StatusBadge } from '@/components/status-badge';
import {
  createApplication,
  deleteApplication,
  listApplications,
  type CreditApplicationDto,
} from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { InlineLoading } from '@/components/page-loading';
import { statusLabel, statusPhase } from '@/lib/status-labels';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

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

export default function ApplicationsPage() {
  const router = useRouter();
  const [applications, setApplications] = useState<CreditApplicationDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const [applicationToDelete, setApplicationToDelete] = useState<CreditApplicationDto | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const fetchApplications = useCallback(async (showLoading = false) => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }
    if (showLoading) setLoading(true);
    try {
      const { applications: freshApplications, meta } = await listApplications();
      setApplications(Array.isArray(freshApplications) ? freshApplications : []);
      setPage(meta.current_page);
      setLastPage(meta.last_page);
      setTotal(meta.total);
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Impossible de charger vos demandes.');
    } finally {
      setLoading(false);
    }
  }, [router]);

  useEffect(() => {
    queueMicrotask(() => void fetchApplications(true));
    const refresh = () => {
      if (document.visibilityState === 'visible') void fetchApplications();
    };
    const interval = window.setInterval(refresh, 10_000);
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', refresh);
    return () => {
      window.clearInterval(interval);
      window.removeEventListener('focus', refresh);
      document.removeEventListener('visibilitychange', refresh);
    };
  }, [fetchApplications]);

  async function handleLoadMore() {
    if (loadingMore || page >= lastPage) return;
    setLoadingMore(true);
    setError(null);
    try {
      const result = await listApplications(page + 1);
      setApplications((current) => {
        const merged = new Map(current.map((application) => [application.id, application]));
        result.applications.forEach((application) => merged.set(application.id, application));
        return Array.from(merged.values());
      });
      setPage(result.meta.current_page);
      setLastPage(result.meta.last_page);
      setTotal(result.meta.total);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Impossible de charger les demandes suivantes.');
    } finally {
      setLoadingMore(false);
    }
  }

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

  async function handleDelete() {
    if (!applicationToDelete || deleting) return;

    setDeleting(true);
    setDeleteError(null);
    try {
      await deleteApplication(applicationToDelete.id);
      setApplications((current) => current.filter((application) => application.id !== applicationToDelete.id));
      setTotal((current) => Math.max(0, current - 1));
      setApplicationToDelete(null);
    } catch (err) {
      setDeleteError(
        err instanceof ApiError
          ? err.message
          : 'Impossible de supprimer ce dossier. Veuillez réessayer.',
      );
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div className="portal-shell">
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

        <div className="portal-page-heading flex flex-col justify-between gap-5 p-6 sm:flex-row sm:items-end sm:p-7">
          <div>
            <p className="overline mb-0.5">Dossiers de Financement</p>
            <h1 className="font-display text-3xl font-light text-[#0C1825]">
              Mes demandes de crédit
            </h1>
            {!loading && (
              <p className="text-xs text-[#3D5166] mt-1">
                {applications.length === 0
                  ? 'Aucune demande enregistrée'
                  : `${total} demande${total > 1 ? 's' : ''} enregistrée${total > 1 ? 's' : ''}`}
              </p>
            )}
          </div>
          <button
            onClick={handleCreate}
            disabled={creating}
            className="btn-red shrink-0 gap-2 px-5 text-xs shadow-xs"
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
                <article
                  key={app.id}
                  className="figma-card overflow-hidden bg-white transition-all hover:border-[#C0272D] hover:shadow-sm group"
                >
                  <Link href={detailPath(app)} className="block p-5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#C0272D]">
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
                  {app.can_be_deleted && (
                    <div className="flex justify-end border-t border-[#E0E4E9] bg-[#FAFBFC] px-4 py-2">
                      <button
                        type="button"
                        onClick={() => {
                          setDeleteError(null);
                          setApplicationToDelete(app);
                        }}
                        className="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-3 text-xs font-semibold text-[#A51D22] transition-colors hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C0272D] focus-visible:ring-offset-2"
                        aria-label={`Supprimer ${app.credit_request?.n_demande ?? `le dossier ${app.id}`}`}
                      >
                        <Trash2 className="size-3.5" aria-hidden="true" />
                        Supprimer le dossier
                      </button>
                    </div>
                  )}
                </article>
              );
            })}
            {page < lastPage && (
              <div className="pt-3 text-center">
                <button
                  type="button"
                  onClick={handleLoadMore}
                  disabled={loadingMore}
                  className="btn-secondary px-5 text-xs"
                >
                  {loadingMore ? 'Chargement…' : 'Afficher plus de demandes'}
                </button>
              </div>
            )}
          </div>
        )}
      </main>

      <Dialog
        open={applicationToDelete !== null}
        onOpenChange={(open) => {
          if (!open && !deleting) {
            setApplicationToDelete(null);
            setDeleteError(null);
          }
        }}
      >
        <DialogContent showCloseButton={false} className="sm:max-w-md">
          <DialogHeader>
            <div className="mb-1 flex size-10 items-center justify-center rounded-full bg-red-50 text-[#C0272D]">
              <TriangleAlert className="size-5" aria-hidden="true" />
            </div>
            <DialogTitle>Supprimer ce dossier inachevé ?</DialogTitle>
            <DialogDescription>
              Le dossier{' '}
              <span className="font-semibold text-[#0C1825]">
                {applicationToDelete?.credit_request?.n_demande ??
                  (applicationToDelete ? `#${applicationToDelete.id}` : '')}
              </span>{' '}
              ne sera plus visible dans vos demandes. Un dossier déjà validé ne peut jamais être supprimé.
            </DialogDescription>
          </DialogHeader>

          {deleteError && (
            <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
              {deleteError}
            </p>
          )}

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              disabled={deleting}
              onClick={() => {
                setApplicationToDelete(null);
                setDeleteError(null);
              }}
            >
              Annuler
            </Button>
            <Button type="button" variant="destructive" disabled={deleting} onClick={handleDelete}>
              <Trash2 aria-hidden="true" />
              {deleting ? 'Suppression…' : 'Supprimer le dossier'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
