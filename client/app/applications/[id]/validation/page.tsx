'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  ArrowRight,
  CheckCircle2,
  ShieldAlert,
  FileText,
  ArrowLeft,
  Lock,
  Building2,
  Send,
  AlertCircle,
  FileWarning,
  Layers,
  User,
} from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { ApplicationStepper } from '@/components/application-stepper';
import { Breadcrumbs, BackLink } from '@/components/breadcrumbs';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  getApplication,
  runValidationOne,
  confirmValidationTwo,
  type CreditApplicationDto,
  type DocumentDto,
} from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { PageLoading } from '@/components/page-loading';
import { DocumentPreviewModal } from '@/components/document-preview-modal';

const DECIDED_STATUSES = new Set<CreditApplicationDto['status']>([
  'APPROVED',
  'REJECTED',
  'STAFF_APPROVED',
  'STAFF_REJECTED',
  'APPOINTMENT_PROPOSED',
  'APPOINTMENT_CONFIRMED',
  'APPOINTMENT_LOCKED',
  'CANCELLED',
]);

interface DisplayValidationIssue {
  filename: string | null;
  messages: string[];
}

function displayValidationIssue(error: string): DisplayValidationIssue {
  const documentMatch = error.match(/^Document «([^»]+)»\s*:\s*(.+)$/u);
  const content = documentMatch?.[2] ?? error;
  const messages = content
    .replace(/\.\s*$/u, '')
    .split(/;\s*/u)
    .map((message) => message.trim())
    .filter(Boolean);

  return {
    filename: documentMatch?.[1] ?? null,
    messages: messages.length > 0 ? messages : [error],
  };
}

export default function ValidationStepPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [application, setApplication] = useState<CreditApplicationDto | null>(null);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [validationErrors, setValidationErrors] = useState<string[] | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [previewDoc, setPreviewDoc] = useState<DocumentDto | null>(null);
  const [previewOpen, setPreviewOpen] = useState(false);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }
    getApplication(applicationId)
      .then(({ application }) => setApplication(application))
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Impossible de charger la demande.'))
      .finally(() => setLoading(false));
  }, [applicationId, router]);

  async function handleValidationOne() {
    setError(null);
    setValidationErrors(null);
    setWorking(true);
    try {
      const { application } = await runValidationOne(applicationId);
      setApplication(application);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setValidationErrors(err.errors);
      } else if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError('Une erreur est survenue lors de la validation. Veuillez vérifier vos saisies.');
      }
    } finally {
      setWorking(false);
    }
  }

  async function handleConfirmLock() {
    setError(null);
    setWorking(true);
    try {
      const { application } = await confirmValidationTwo(applicationId);
      setApplication(application);
      setConfirmOpen(false);
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError('Une erreur est survenue lors de la soumission.');
      }
    } finally {
      setWorking(false);
    }
  }

  if (loading) return <PageLoading />;

  if (!application) {
    return (
      <div className="portal-shell">
        <DashboardHeader />
        <main id="main" className="mx-auto max-w-3xl px-4 py-10 space-y-4">
          <BackLink href="/applications" label="Retour à mes demandes" />
          <ErrorAlert message={error} />
        </main>
      </div>
    );
  }

  const readyForValidation =
    application.client && application.credit_request && application.project;

  const readOnly = DECIDED_STATUSES.has(application.status) || application.status === 'SUBMITTED';

  return (
    <div className="portal-shell">
      <DashboardHeader />
      <main id="main" className="mx-auto max-w-4xl px-4 sm:px-8 py-8 sm:py-10 space-y-6">
        <BackLink href={`/applications/${applicationId}`} label="Retour aux détails" />
        <Breadcrumbs
          items={[
            { label: 'Tableau de bord', href: '/dashboard' },
            { label: 'Mes demandes', href: '/applications' },
            { label: application.credit_request?.n_demande ?? `Dossier #${applicationId}`, href: `/applications/${applicationId}` },
            { label: 'Vérification & Soumission' },
          ]}
          className="mb-2"
        />

        {/* ── Persistent Reference Banner ── */}
        <div className="figma-card p-4 bg-white border-l-4 border-l-[#C0272D] flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-2">
            <Lock className="size-4 text-[#C0272D]" />
            <span className="text-xs font-bold uppercase tracking-wider text-[#0C1825]">
              Référence Dossier :
            </span>
            <span className="font-mono text-xs font-bold text-[#C0272D] bg-[#FDF2F2] px-2 py-0.5 rounded border border-[#FECACA]">
              {application.credit_request?.n_demande ?? `DOSSIER-#${applicationId}`}
            </span>
          </div>
        </div>

        {/* ── Stepper ── */}
        <ApplicationStepper status={application.status} current="validation" />

        <ErrorAlert message={error} />

        {/* ── READ-ONLY: Already submitted or decided ── */}
        {readOnly && (
          <div className="space-y-6">
            {application.status === 'SUBMITTED' && (
              <div className="figma-card p-8 bg-white text-center space-y-4 border-t-4 border-t-[#C0272D]">
                <div className="size-14 rounded-full bg-[#FDF2F2] text-[#C0272D] mx-auto flex items-center justify-center">
                  <CheckCircle2 className="size-8" />
                </div>
                <div className="space-y-1">
                  <h2 className="font-display text-2xl font-light text-[#0C1825]">
                    Dossier soumis avec succès
                  </h2>
                  <p className="text-xs text-[#3D5166] max-w-md mx-auto">
                    Votre demande est désormais en cours d&apos;étude par le personnel de la BTS Bank.
                  </p>
                </div>
                {application.credit_request?.n_demande && (
                  <p className="text-xs">
                    N° de référence : <span className="font-mono font-bold text-[#C0272D]">{application.credit_request.n_demande}</span>
                  </p>
                )}
                {application.submitted_at && (
                  <p className="text-[11px] text-[#3D5166]">
                    Transmis le {new Date(application.submitted_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}
                  </p>
                )}
                <div className="flex flex-wrap justify-center gap-3 pt-2">
                  <Link
                    href={`/applications/${applicationId}`}
                    className="btn-red text-xs inline-flex items-center gap-2"
                  >
                    <span>Voir le récapitulatif</span>
                    <ArrowRight className="size-3.5" />
                  </Link>
                  <Link
                    href="/applications"
                    className="btn-outline text-xs inline-flex items-center gap-2"
                  >
                    Retour aux demandes
                  </Link>
                </div>
              </div>
            )}

            {/* Branch card */}
            {application.branch && (
              <div className="figma-card p-5 bg-white space-y-2">
                <div className="flex items-center gap-2 text-[#0C1825] font-semibold text-xs">
                  <Building2 className="size-4 text-[#C0272D]" />
                  <span>Agence de rattachement : {application.branch.name}</span>
                </div>
                <p className="text-xs text-[#3D5166] pl-6">
                  {[application.branch.ville, application.branch.address].filter(Boolean).join(' — ')}
                </p>
              </div>
            )}
          </div>
        )}

        {/* ── NOT YET READY ── */}
        {!readOnly && !readyForValidation && (
          <div className="figma-card p-8 bg-white text-center space-y-3">
            <AlertCircle className="size-8 text-amber-500 mx-auto" />
            <p className="text-sm font-semibold text-[#0C1825]">
              Veuillez compléter les étapes préalables avant la validation.
            </p>
            <p className="text-xs text-[#3D5166]">
              Les volets Informations Client, Crédit et Projet doivent être renseignés.
            </p>
            <Link
              href={`/applications/${applicationId}/client`}
              className="btn-red text-xs inline-flex items-center gap-2 mt-2"
            >
              Reprendre à l&apos;étape 1
            </Link>
          </div>
        )}

        {/* ── EDITABLE & READY FOR VALIDATION ── */}
        {!readOnly && readyForValidation && (
          <div className="space-y-6">
            {/* Review Cards Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {/* Demandeur */}
              <div className="figma-card p-5 bg-white space-y-3">
                <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-2.5">
                  <span className="text-xs font-bold text-[#0C1825] flex items-center gap-2">
                    <User className="size-3.5 text-[#C0272D]" /> 1. Informations Demandeur
                  </span>
                  <Link href={`/applications/${applicationId}/client`} className="text-[11px] text-[#C0272D] font-semibold hover:underline">
                    Modifier
                  </Link>
                </div>
                <div className="space-y-1.5 text-xs text-[#3D5166]">
                  <p><strong>Nom :</strong> {application.client!.prenom} {application.client!.nom}</p>
                  <p><strong>Pièce :</strong> {application.client!.type_pid} ({application.client!.numero_pid})</p>
                  <p><strong>Profession :</strong> {application.client!.profession || '—'}</p>
                </div>
              </div>

              {/* Crédit */}
              <div className="figma-card p-5 bg-white space-y-3">
                <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-2.5">
                  <span className="text-xs font-bold text-[#0C1825] flex items-center gap-2">
                    <FileText className="size-3.5 text-[#C0272D]" /> 2. Demande de Financement
                  </span>
                  <Link href={`/applications/${applicationId}/credit`} className="text-[11px] text-[#C0272D] font-semibold hover:underline">
                    Modifier
                  </Link>
                </div>
                <div className="space-y-1.5 text-xs text-[#3D5166]">
                  <p><strong>Type :</strong> {application.credit_request!.type_demande}</p>
                  <p><strong>Montant :</strong> <span className="font-bold text-[#C0272D]">{application.credit_request!.montant_global_sollicite} {application.credit_request!.code_devise}</span></p>
                </div>
              </div>

              {/* Projet */}
              <div className="figma-card p-5 bg-white space-y-3 sm:col-span-2">
                <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-2.5">
                  <span className="text-xs font-bold text-[#0C1825] flex items-center gap-2">
                    <Layers className="size-3.5 text-[#C0272D]" /> 3. Descriptif du Projet
                  </span>
                  <Link href={`/applications/${applicationId}/project`} className="text-[11px] text-[#C0272D] font-semibold hover:underline">
                    Modifier
                  </Link>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs text-[#3D5166]">
                  <p><strong>Type :</strong> {application.project!.type_projet}</p>
                  <p><strong>Activité :</strong> {application.project!.activite || '—'}</p>
                  <p><strong>Coût global :</strong> {application.project!.cout || '—'} TND</p>
                </div>
              </div>
            </div>

            {/* Documents List */}
            <div className="figma-card p-5 bg-white space-y-3">
              <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-2.5">
                <span className="text-xs font-bold text-[#0C1825]">
                  Documents enregistrés ({application.documents?.length || 0})
                </span>
              </div>
              {application.documents && application.documents.length > 0 ? (
                <ul className="space-y-2 text-xs">
                  {application.documents.map((doc) => (
                    <li key={doc.id} className="flex items-center justify-between gap-3 p-2.5 rounded-lg border border-[#E0E4E9] bg-[#F4F6F8]">
                      <span className="truncate font-medium text-[#0C1825]">{doc.original_filename}</span>
                      <button
                        type="button"
                        onClick={() => {
                          setPreviewDoc(doc);
                          setPreviewOpen(true);
                        }}
                        className="text-[#C0272D] hover:underline text-[11px] font-semibold"
                      >
                        Aperçu
                      </button>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-xs text-[#3D5166]">Aucun document joint pour le moment.</p>
              )}
            </div>

            {/* Validation Errors */}
            {validationErrors && validationErrors.length > 0 && (
              <div
                role="alert"
                aria-live="assertive"
                className="overflow-hidden rounded-2xl border border-red-200 bg-red-50 shadow-xs"
              >
                <div className="flex items-start gap-3 border-b border-red-200 bg-red-100/70 px-4 py-3 sm:px-5">
                  <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-white text-red-600 shadow-xs">
                    <ShieldAlert className="size-4" aria-hidden="true" />
                  </span>
                  <div>
                    <h2 className="text-sm font-bold text-red-950">Documents à corriger</h2>
                    <p className="mt-0.5 text-xs leading-relaxed text-red-800">
                      La vérification ne peut pas continuer. Voici exactement ce que vous devez modifier.
                    </p>
                  </div>
                </div>

                <div className="space-y-3 p-4 sm:p-5">
                  {validationErrors.map((validationError, index) => {
                    const issue = displayValidationIssue(validationError);

                    return (
                      <section
                        key={`${issue.filename ?? 'dossier'}-${index}`}
                        className="rounded-xl border border-red-200 bg-white p-4 shadow-xs"
                      >
                        <div className="flex items-start gap-3">
                          <FileWarning className="mt-0.5 size-5 shrink-0 text-red-600" aria-hidden="true" />
                          <div className="min-w-0 flex-1">
                            <h3 className="break-words text-xs font-bold text-[#0C1825]">
                              {issue.filename ?? 'Dossier incomplet'}
                            </h3>
                            <ul className="mt-2 space-y-2">
                              {issue.messages.map((message, messageIndex) => (
                                <li key={messageIndex} className="flex items-start gap-2 text-xs leading-relaxed text-red-900">
                                  <AlertCircle className="mt-0.5 size-3.5 shrink-0 text-red-500" aria-hidden="true" />
                                  <span>{message}</span>
                                </li>
                              ))}
                            </ul>
                          </div>
                        </div>
                      </section>
                    );
                  })}

                  <p className="text-xs font-medium text-red-900">
                    Après correction, relancez la vérification de conformité.
                  </p>
                </div>
              </div>
            )}

            {/* Validation Buttons & Final Lock */}
            {application.status === 'VALIDATION_1_COMPLETED' ? (
              <div className="space-y-4">
                <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-xl text-xs text-emerald-900 flex items-center gap-2.5">
                  <CheckCircle2 className="size-4 text-emerald-600 shrink-0" />
                  <span>Vérification technique réussie. Vous pouvez maintenant soumettre définitivement votre dossier.</span>
                </div>

                <div className="flex gap-4">
                  <Link
                    href={`/applications/${applicationId}/project`}
                    className="btn-outline text-xs inline-flex items-center gap-2"
                  >
                    <ArrowLeft className="size-3.5" /> Retour
                  </Link>

                  <button
                    type="button"
                    onClick={() => setConfirmOpen(true)}
                    className="btn-red text-xs flex-1 shadow-xs"
                    style={{ padding: '10px 24px' }}
                  >
                    Confirmer et transmettre le dossier
                  </button>
                </div>

                {/* Confirmation Modal */}
                <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                  <DialogContent className="max-w-md">
                    <DialogHeader>
                      <DialogTitle className="font-display text-xl text-[#0C1825]">
                        Transmettre définitivement votre dossier ?
                      </DialogTitle>
                      <DialogDescription className="text-xs text-[#3D5166] pt-2 leading-relaxed">
                        Une fois transmis, les informations saisies et les documents joints seront verrouillés pour examen par les conseillers BTS Bank.
                      </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2 pt-4">
                      <button
                        type="button"
                        onClick={() => setConfirmOpen(false)}
                        className="btn-outline text-xs"
                      >
                        Annuler
                      </button>
                      <button
                        type="button"
                        onClick={handleConfirmLock}
                        disabled={working}
                        className="btn-red text-xs inline-flex items-center gap-1.5"
                      >
                        <Send className="size-3.5" />
                        <span>{working ? 'Transmission en cours…' : 'Oui, transmettre mon dossier'}</span>
                      </button>
                    </DialogFooter>
                  </DialogContent>
                </Dialog>
              </div>
            ) : (
              <button
                type="button"
                onClick={handleValidationOne}
                disabled={working}
                className="btn-red text-xs w-full shadow-xs"
                style={{ padding: '12px 24px' }}
              >
                <span>{working ? 'Vérification en cours…' : 'Lancer la vérification de conformité'}</span>
              </button>
            )}
          </div>
        )}
      </main>

      <DocumentPreviewModal
        applicationId={applicationId}
        document={previewDoc}
        open={previewOpen}
        onOpenChange={setPreviewOpen}
      />
    </div>
  );
}
