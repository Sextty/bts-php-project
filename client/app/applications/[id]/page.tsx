'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  ArrowRight,
  Calendar,
  CheckCircle2,
  Clock,
  FileText,
  MapPin,
  MessageSquare,
  User,
  Building2,
  AlertCircle,
  XCircle,
  Shield,
  UploadCloud,
  Layers,
} from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { StatusBadge } from '@/components/status-badge';
import { Breadcrumbs, BackLink } from '@/components/breadcrumbs';
import {
  getApplication,
  type CreditApplicationDto,
  type DocumentDto,
} from '@/lib/api/credit-applications';
import { statusLabel, statusDescription, statusPhase } from '@/lib/status-labels';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { PageLoading } from '@/components/page-loading';

const TIMELINE_STEPS = [
  { key: 'draft', label: 'Demande créée', phase: 'draft' },
  { key: 'documents', label: 'Pièces jointes', phase: 'draft' },
  { key: 'validation', label: 'Validation technique', phase: 'validation' },
  { key: 'submitted', label: 'Dossier transmis', phase: 'submitted' },
  { key: 'review', label: 'Étude en agence', phase: 'review' },
  { key: 'appointment', label: 'Rendez-vous', phase: 'appointment' },
] as const;

function getTimelineActiveIndex(status: string): number {
  const phase = statusPhase(status);
  if (phase === 'terminal') {
    if (status === 'REJECTED' || status === 'STAFF_REJECTED') return 4;
    if (status === 'CANCELLED') return 3;
    return 5;
  }
  const idx = TIMELINE_STEPS.findIndex((s) => s.phase === phase);
  return idx >= 0 ? idx : 0;
}

export default function ApplicationDetailPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [application, setApplication] = useState<CreditApplicationDto | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

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

  if (loading) return <PageLoading />;

  if (!application) {
    return (
      <div className="min-h-screen bg-[#F4F6F8] text-[#1E2D3D]">
        <DashboardHeader />
        <main id="main" className="mx-auto max-w-4xl px-4 sm:px-8 py-10 space-y-4">
          <BackLink href="/applications" label="Retour à mes demandes" />
          <ErrorAlert message={error ?? 'Demande introuvable.'} />
        </main>
      </div>
    );
  }

  const status = application.status;
  const phase = statusPhase(status);
  const isTerminal = phase === 'terminal';
  const canEdit =
    status === 'DRAFT' ||
    status === 'STEP_1_COMPLETED' ||
    status === 'STEP_2_COMPLETED' ||
    status === 'STEP_3_COMPLETED' ||
    status === 'READY_FOR_VALIDATION_1' ||
    status === 'VALIDATION_1_COMPLETED';

  function resumeHref(): string {
    if (status === 'DRAFT') return `/applications/${applicationId}/client`;
    if (status === 'STEP_1_COMPLETED') return `/applications/${applicationId}/credit`;
    if (status === 'STEP_2_COMPLETED') return `/applications/${applicationId}/project`;
    if (['APPOINTMENT_PROPOSED', 'APPOINTMENT_CONFIRMED', 'APPOINTMENT_LOCKED'].includes(status))
      return `/applications/${applicationId}/appointment`;
    if (status === 'CANCELLED') return `/applications/${applicationId}/report`;
    return `/applications/${applicationId}/validation`;
  }

  const formattedAmount = application.credit_request?.montant_global_sollicite
    ? new Intl.NumberFormat('fr-TN', { maximumFractionDigits: 0 }).format(
        Number(application.credit_request.montant_global_sollicite)
      ) +
      ' ' +
      (application.credit_request.code_devise ?? 'TND')
    : 'Non renseigné';

  return (
    <div className="min-h-screen bg-[#F4F6F8] text-[#1E2D3D]">
      <DashboardHeader />
      <main id="main" className="mx-auto max-w-5xl px-4 sm:px-8 py-8 sm:py-10 space-y-6">
        <BackLink href="/applications" label="Retour à mes demandes" />
        <Breadcrumbs
          items={[
            { label: 'Tableau de bord', href: '/dashboard' },
            { label: 'Mes demandes', href: '/applications' },
            { label: application.credit_request?.n_demande ?? `Dossier #${application.id}` },
          ]}
          className="mb-2"
        />

        {/* ── Status Hero Header ── */}
        <div className="figma-card p-6 bg-white space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
            <div className="space-y-1">
              <p className="overline">Détail du Dossier de Crédit</p>
              <h1 className="font-display text-3xl font-light text-[#0C1825]">
                {application.credit_request?.n_demande ?? `Dossier #${application.id}`}
              </h1>
              <p className="text-xs text-[#3D5166]">
                Créé le {new Date(application.created_at).toLocaleDateString('fr-FR')}
                {application.submitted_at && (
                  <span className="text-emerald-700 font-medium">
                    {' '}· Transmis le {new Date(application.submitted_at).toLocaleDateString('fr-FR')}
                  </span>
                )}
              </p>
            </div>
            <div className="flex items-center gap-2 shrink-0">
              <StatusBadge status={status} label={statusLabel(status)} />
            </div>
          </div>

          <div className="p-3.5 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] text-xs text-[#3D5166] flex items-center gap-2">
            <Shield className="size-4 text-[#C0272D] shrink-0" />
            <span>{statusDescription(status)}</span>
          </div>

          {/* Quick Primary Actions */}
          <div className="flex flex-wrap gap-3 pt-2">
            {canEdit && (
              <Link
                href={resumeHref()}
                className="btn-red text-xs inline-flex items-center gap-2"
                style={{ padding: '8px 18px' }}
              >
                <span>Poursuivre la saisie</span>
                <ArrowRight className="size-3.5" />
              </Link>
            )}
            {status === 'CANCELLED' && (
              <Link
                href={`/applications/${applicationId}/report`}
                className="btn-outline text-xs inline-flex items-center gap-2"
              >
                <MessageSquare className="size-3.5 text-[#C0272D]" />
                <span>Ouvrir la discussion</span>
              </Link>
            )}
            {['APPOINTMENT_PROPOSED', 'APPOINTMENT_CONFIRMED', 'APPOINTMENT_LOCKED'].includes(status) && (
              <Link
                href={`/applications/${applicationId}/appointment`}
                className="btn-red text-xs inline-flex items-center gap-2"
              >
                <Calendar className="size-3.5" />
                <span>Gérer mon rendez-vous</span>
              </Link>
            )}
            {['SUBMITTED', 'STAFF_APPROVED', 'APPROVED'].includes(status) && (
              <Link
                href={resumeHref()}
                className="btn-outline text-xs inline-flex items-center gap-2"
              >
                <FileText className="size-3.5 text-[#C0272D]" />
                <span>Consulter la validation</span>
              </Link>
            )}
          </div>
        </div>

        {/* ── Alerts for specific statuses ── */}
        {status === 'CANCELLED' && (
          <div className="p-4 bg-red-50 border border-red-200 rounded-xl text-xs text-red-900 space-y-1">
            <div className="flex items-center gap-2 font-bold text-red-800">
              <XCircle className="size-4 text-red-600" />
              <span>Dossier annulé</span>
            </div>
            {application.rejection_reason && (
              <p>Motif : {application.rejection_reason}</p>
            )}
            <p className="text-red-700">Vous pouvez échanger avec l&apos;agence via l&apos;espace discussion.</p>
          </div>
        )}

        {(status === 'REJECTED' || status === 'STAFF_REJECTED') && (
          <div className="p-4 bg-red-50 border border-red-200 rounded-xl text-xs text-red-900 space-y-1">
            <div className="flex items-center gap-2 font-bold text-red-800">
              <AlertCircle className="size-4 text-red-600" />
              <span>Dossier non retenu</span>
            </div>
            {application.rejection_reason && (
              <p>Motif : {application.rejection_reason}</p>
            )}
          </div>
        )}

        {status === 'SUBMITTED' && (
          <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-xl text-xs text-emerald-900 flex items-center gap-2.5">
            <CheckCircle2 className="size-4 text-emerald-600 shrink-0" />
            <span>Votre dossier a été transmis avec succès à l&apos;agence régionale BTS Bank pour instruction.</span>
          </div>
        )}

        {/* ── Main Information Cards (Grid) ── */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {/* 1. Client Details */}
          {application.client && (
            <div className="figma-card p-6 bg-white space-y-4">
              <div className="flex items-center gap-2 border-b border-[#E0E4E9] pb-3">
                <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                  <User className="size-4" />
                </div>
                <h3 className="text-sm font-semibold text-[#0C1825]">Informations Demandeur</h3>
              </div>
              <div className="grid grid-cols-2 gap-3 text-xs">
                <SummaryItem label="Nom complet" value={`${application.client.prenom} ${application.client.nom}`} />
                <SummaryItem label="Code client" value={application.client.code_client || 'Non assigné'} isMono />
                <SummaryItem label="Pièce d'identité" value={`${application.client.type_pid || 'CIN'} : ${application.client.numero_pid || '—'}`} />
                <SummaryItem label="Profession" value={application.client.profession || '—'} />
              </div>
            </div>
          )}

          {/* 2. Credit Details */}
          {application.credit_request && (
            <div className="figma-card p-6 bg-white space-y-4">
              <div className="flex items-center gap-2 border-b border-[#E0E4E9] pb-3">
                <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                  <FileText className="size-4" />
                </div>
                <h3 className="text-sm font-semibold text-[#0C1825]">Détails du Financement</h3>
              </div>
              <div className="grid grid-cols-2 gap-3 text-xs">
                <SummaryItem label="Type de crédit" value={application.credit_request.type_demande || 'Crédit Professionnel'} />
                <SummaryItem label="Montant sollicité" value={formattedAmount} isHighlight />
                <SummaryItem label="Nombre de crédits" value={String(application.credit_request.nombre_credits_sollicites || 1)} />
                <SummaryItem label="Unité de dépôt" value={application.credit_request.unite_depot || 'Portail en ligne'} />
              </div>
            </div>
          )}

          {/* 3. Project Details */}
          {application.project && (
            <div className="figma-card p-6 bg-white space-y-4">
              <div className="flex items-center gap-2 border-b border-[#E0E4E9] pb-3">
                <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                  <Layers className="size-4" />
                </div>
                <h3 className="text-sm font-semibold text-[#0C1825]">Descriptif du Projet</h3>
              </div>
              <div className="grid grid-cols-2 gap-3 text-xs">
                <SummaryItem label="Type de projet" value={application.project.type_projet || 'Création / Extension'} />
                <SummaryItem label="Activité" value={application.project.activite || '—'} />
                <SummaryItem
                  label="Localisation"
                  value={[application.project.ville, application.project.delegation].filter(Boolean).join(', ') || '—'}
                />
                <SummaryItem label="Coût global estimé" value={application.project.cout || '—'} />
              </div>
            </div>
          )}

          {/* 4. Branch / Agency Assigned */}
          {application.branch && (
            <div className="figma-card p-6 bg-white space-y-4">
              <div className="flex items-center gap-2 border-b border-[#E0E4E9] pb-3">
                <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                  <Building2 className="size-4" />
                </div>
                <h3 className="text-sm font-semibold text-[#0C1825]">Agence Régionale de Rattachement</h3>
              </div>
              <div className="space-y-2 text-xs">
                <p className="font-bold text-[#0C1825] text-sm">{application.branch.name}</p>
                <p className="text-[#3D5166] flex items-center gap-1.5">
                  <MapPin className="size-3.5 text-[#C0272D] shrink-0" />
                  {[application.branch.ville, application.branch.address].filter(Boolean).join(' — ')}
                </p>
              </div>
            </div>
          )}
        </div>

        {/* ── 5. Documents List ── */}
        {application.documents && application.documents.length > 0 && (
          <div className="figma-card p-6 bg-white space-y-4">
            <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-3">
              <div className="flex items-center gap-2">
                <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                  <UploadCloud className="size-4" />
                </div>
                <h3 className="text-sm font-semibold text-[#0C1825]">Documents & Pièces Justificatives</h3>
              </div>
              <span className="text-[10px] font-bold bg-[#F4F6F8] px-2 py-0.5 rounded text-[#3D5166] border border-[#E0E4E9]">
                {application.documents.length} pièce{application.documents.length > 1 ? 's' : ''}
              </span>
            </div>

            <ul className="space-y-2 text-xs">
              {application.documents.map((doc) => (
                <li
                  key={doc.id}
                  className="flex items-center justify-between gap-3 p-3 rounded-lg border border-[#E0E4E9] bg-[#F4F6F8]"
                >
                  <div className="flex items-center gap-2 min-w-0">
                    <FileText className="size-4 text-[#C0272D] shrink-0" />
                    <span className="font-medium text-[#0C1825] truncate">{doc.original_filename}</span>
                  </div>
                  <span className="shrink-0">
                    {doc.ai_verified_at ? (
                      <span className={`badge ${doc.ai_is_valid ? 'badge-success' : 'badge-danger'} text-[10px]`}>
                        {doc.ai_is_valid ? 'Conforme' : 'Non conforme'}
                      </span>
                    ) : (
                      <span className="badge badge-neutral text-[10px]">Enregistré</span>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )}

        {/* ── 6. Horizontal Process Timeline Tracker ── */}
        <div className="figma-card p-6 bg-white space-y-4">
          <div className="flex items-center gap-2 border-b border-[#E0E4E9] pb-3">
            <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
              <Clock className="size-4" />
            </div>
            <h3 className="text-sm font-semibold text-[#0C1825]">Chronologie d&apos;Instruction</h3>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3 pt-2">
            {TIMELINE_STEPS.map((step, index) => {
              const active = index <= getTimelineActiveIndex(status);
              return (
                <div
                  key={step.key}
                  className={`p-3 rounded-lg border text-center space-y-1.5 ${
                    active
                      ? 'bg-[#FDF2F2] border-[#FECACA]'
                      : 'bg-[#F4F6F8] border-[#E0E4E9] opacity-60'
                  }`}
                >
                  <div className="flex items-center justify-center">
                    <div
                      className={`size-6 rounded-full flex items-center justify-center text-[10px] font-bold ${
                        active ? 'bg-[#C0272D] text-white' : 'bg-gray-200 text-gray-600'
                      }`}
                    >
                      {index + 1}
                    </div>
                  </div>
                  <p className={`text-[11px] leading-tight ${active ? 'font-bold text-[#0C1825]' : 'text-[#3D5166]'}`}>
                    {step.label}
                  </p>
                </div>
              );
            })}
          </div>
        </div>
      </main>
    </div>
  );
}

function SummaryItem({
  label,
  value,
  isMono = false,
  isHighlight = false,
}: {
  label: string;
  value: string;
  isMono?: boolean;
  isHighlight?: boolean;
}) {
  return (
    <div className="space-y-0.5">
      <span className="text-[10px] uppercase font-bold text-[#3D5166] tracking-wider block">{label}</span>
      <span
        className={`text-xs block ${
          isHighlight
            ? 'font-bold text-[#C0272D] text-sm'
            : isMono
            ? 'font-mono font-semibold text-[#0C1825]'
            : 'font-medium text-[#0C1825]'
        }`}
      >
        {value}
      </span>
    </div>
  );
}
