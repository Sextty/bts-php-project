'use client';

import { useState } from 'react';
import Link from 'next/link';
import {
  AlertCircle,
  AlertTriangle,
  CircleCheck,
  CircleHelp,
  CircleX,
  MessageSquare,
  User,
  FileText,
  Layers,
  MapPin,
  UploadCloud,
  CheckCircle2,
  XCircle,
  Clock,
  Building2,
  ShieldCheck,
  ShieldAlert,
  ArrowRight,
  Eye,
  Send,
} from 'lucide-react';
import { ErrorAlert } from '@/components/error-alert';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { StaffDocumentPreviewModal } from '@/components/staff-document-preview-modal';
import type { StaffApplicationDto } from '@/lib/api/staff';
import type { DocumentDto } from '@/lib/api/credit-applications';
import { Sparkles, Download } from 'lucide-react';

export function ApplicationReviewDetail({
  application,
  canDecide,
  onApprove,
  onReject,
  onCancel,
  working,
  error,
}: {
  application: StaffApplicationDto;
  canDecide: boolean;
  onApprove: () => void;
  onReject: (reason: string) => void;
  onCancel?: () => void;
  working: boolean;
  error: string | null;
}) {
  const [rejectOpen, setRejectOpen] = useState(false);
  const [approveOpen, setApproveOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [previewDoc, setPreviewDoc] = useState<DocumentDto | null>(null);
  const [previewOpen, setPreviewOpen] = useState(false);
  const [reason, setReason] = useState('');

  const formattedAmount = application.credit_request?.montant_global_sollicite
    ? new Intl.NumberFormat('fr-TN', { maximumFractionDigits: 0 }).format(
        Number(application.credit_request.montant_global_sollicite)
      ) +
      ' ' +
      (application.credit_request.code_devise ?? 'TND')
    : '—';

  return (
    <div className="space-y-6">
      <ErrorAlert message={error} />

      {/* ── Status Banner ── */}
      <div className="figma-card p-6 bg-white space-y-3 border-l-4 border-l-[#C0272D]">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="space-y-1">
            <p className="overline">Instruction de Crédit</p>
            <h1 className="font-display text-2xl sm:text-3xl font-light text-[#0C1825]">
              {application.credit_request?.n_demande ?? `Dossier #${application.id}`}
            </h1>
            <p className="text-xs text-[#3D5166]">
              Soumis le{' '}
              {application.submitted_at
                ? new Date(application.submitted_at).toLocaleDateString('fr-FR')
                : new Date(application.created_at).toLocaleDateString('fr-FR')}
            </p>
          </div>

          <div className="flex items-center gap-2">
            <span className={`badge ${statusToBadgeClass(application.status)} text-xs px-3 py-1`}>
              {statusToFrench(application.status)}
            </span>
          </div>
        </div>
      </div>

      {/* ── 4 Quadrant Grid ── */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* 1. Demandeur / Client */}
        <div className="figma-card p-6 bg-white space-y-4">
          <div className="flex items-center gap-2 border-b border-[#E0E4E9] pb-3">
            <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
              <User className="size-4" />
            </div>
            <h3 className="text-sm font-semibold text-[#0C1825]">1. Informations Demandeur</h3>
          </div>

          <div className="grid grid-cols-2 gap-3 text-xs">
            <ReviewItem label="Nom du compte" value={application.applicant?.name ?? '—'} />
            <ReviewItem label="Email" value={application.applicant?.email ?? '—'} />
            <ReviewItem label="Téléphone" value={application.applicant?.phone ?? '—'} />
            <ReviewItem
              label="Nom civil"
              value={application.client ? `${application.client.prenom} ${application.client.nom}` : '—'}
            />
            <ReviewItem label="Code Client" value={application.client?.code_client ?? '—'} isMono />
            <ReviewItem
              label="Pièce d'identité"
              value={
                application.client ? `${application.client.type_pid} : ${application.client.numero_pid}` : '—'
              }
            />
            <ReviewItem label="Profession" value={application.client?.profession ?? '—'} />
            <ReviewItem label="État Civil" value={application.client?.etat_civil ?? '—'} />
          </div>
        </div>

        {/* 2. Demande de Financement */}
        <div className="figma-card p-6 bg-white space-y-4">
          <div className="flex items-center gap-2 border-b border-[#E0E4E9] pb-3">
            <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
              <FileText className="size-4" />
            </div>
            <h3 className="text-sm font-semibold text-[#0C1825]">2. Paramètres du Crédit</h3>
          </div>

          <div className="grid grid-cols-2 gap-3 text-xs">
            <ReviewItem
              label="Type de financement"
              value={application.credit_request?.type_demande ?? 'Crédit Professionnel'}
            />
            <ReviewItem label="Montant sollicité" value={formattedAmount} isHighlight />
            <ReviewItem
              label="Raison Sociale"
              value={application.credit_request?.nom_ou_rs ?? '—'}
            />
            <ReviewItem
              label="Origine"
              value={application.credit_request?.origine ?? 'Portail en ligne'}
            />
            <ReviewItem
              label="Date de dépôt"
              value={application.credit_request?.date_depot ?? '—'}
            />
            <ReviewItem
              label="Unité de dépôt"
              value={application.credit_request?.unite_depot ?? 'Agence régionale'}
            />
          </div>
        </div>

        {/* 3. Descriptif Projet */}
        {application.project && (
          <div className="figma-card p-6 bg-white space-y-4 md:col-span-2">
            <div className="flex items-center gap-2 border-b border-[#E0E4E9] pb-3">
              <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                <Layers className="size-4" />
              </div>
              <h3 className="text-sm font-semibold text-[#0C1825]">3. Projet & Plan d&apos;Investissement</h3>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
              <ReviewItem label="Type de projet" value={application.project.type_projet ?? '—'} />
              <ReviewItem label="Activité" value={application.project.activite ?? '—'} />
              <ReviewItem label="Objet" value={application.project.objet ?? '—'} />
              <ReviewItem
                label="Localisation"
                value={[application.project.ville, application.project.delegation].filter(Boolean).join(', ') || '—'}
              />
              <ReviewItem label="Coût Global Estimé" value={`${application.project.cout || '—'} TND`} />
              <ReviewItem label="Apport Personnel" value={`${application.project.investissement_personnel || '—'} TND`} />
            </div>

            {application.project.description && (
              <div className="pt-2 border-t border-[#E0E4E9] text-xs text-[#3D5166]">
                <span className="font-bold text-[#0C1825] block mb-1">Description fournie par le client :</span>
                <p className="p-3 bg-[#F4F6F8] rounded-lg border border-[#E0E4E9] italic">
                  &ldquo;{application.project.description}&rdquo;
                </p>
              </div>
            )}
          </div>
        )}

        {/* 4. Documents & Conformité IA */}
        <div className="figma-card p-6 bg-white space-y-4 md:col-span-2">
          <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-3">
            <div className="flex items-center gap-2">
              <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                <UploadCloud className="size-4" />
              </div>
              <h3 className="text-sm font-semibold text-[#0C1825]">
                4. Pièces Justificatives & Contrôle de Conformité
              </h3>
            </div>
            <span className="text-xs text-[#3D5166] font-bold">
              {application.documents?.length || 0} document{application.documents?.length && application.documents.length > 1 ? 's' : ''}
            </span>
          </div>

          {application.documents && application.documents.length > 0 ? (
            <div className="space-y-3">
              {application.documents.map((doc) => {
                const hasExtracted =
                  doc.ai_extracted_fields && Object.keys(doc.ai_extracted_fields).length > 0;
                const mismatchesCount = doc.ai_mismatches?.length || 0;

                return (
                  <div
                    key={doc.id}
                    className="p-4 rounded-xl border border-[#E0E4E9] bg-[#F4F6F8] space-y-3"
                  >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <div className="flex items-center gap-2.5 min-w-0">
                        <div className="size-8 rounded-lg bg-white border border-[#E0E4E9] text-[#C0272D] flex items-center justify-center shrink-0">
                          <FileText className="size-4" />
                        </div>
                        <div className="min-w-0">
                          <p className="font-bold text-xs text-[#0C1825] truncate" title={doc.original_filename}>
                            {doc.original_filename}
                          </p>
                          <p className="text-[11px] text-[#3D5166]">
                            <span className="font-medium text-[#C0272D]">{doc.document_type || 'Justificatif'}</span>
                            {doc.size_bytes ? ` • ${Math.round(doc.size_bytes / 1024)} Ko` : ''}
                            {doc.uploaded_at ? ` • ${new Date(doc.uploaded_at).toLocaleDateString('fr-FR')}` : ''}
                          </p>
                        </div>
                      </div>

                      <div className="flex flex-wrap items-center gap-2.5">
                        {doc.ai_verified_at ? (
                          <span
                            className={`badge ${
                              doc.ai_is_valid ? 'badge-success' : 'badge-danger'
                            } text-[10px] inline-flex items-center gap-1`}
                          >
                            {doc.ai_is_valid ? (
                              <CheckCircle2 className="size-3" />
                            ) : (
                              <XCircle className="size-3" />
                            )}
                            <span>{doc.ai_is_valid ? 'Conforme IA' : 'Non conforme IA'}</span>
                          </span>
                        ) : (
                          <span className="badge badge-neutral text-[10px]">Enregistré</span>
                        )}

                        <button
                          type="button"
                          onClick={() => {
                            setPreviewDoc(doc);
                            setPreviewOpen(true);
                          }}
                          className="btn-red text-[11px] inline-flex items-center gap-1.5 shadow-xs"
                          style={{ padding: '6px 14px' }}
                        >
                          <Eye className="size-3.5" />
                          <span>Visualiser & Données IA</span>
                        </button>
                      </div>
                    </div>

                    {/* AI Observations / Extracted preview summary */}
                    {(doc.ai_comment || hasExtracted || mismatchesCount > 0) && (
                      <div className="pt-2.5 border-t border-[#E0E4E9]/80 space-y-2">
                        {doc.ai_comment && (
                          <div className="text-[11px] text-[#1E2D3D] bg-white p-2.5 rounded-lg border border-[#E0E4E9] flex items-start gap-2">
                            <Sparkles className="size-3.5 text-[#C0272D] shrink-0 mt-0.5" />
                            <span className="leading-snug">{doc.ai_comment}</span>
                          </div>
                        )}

                        {mismatchesCount > 0 && (
                          <div className="text-[11px] text-red-700 bg-red-50 p-2 rounded-lg border border-red-200 flex items-center gap-1.5 font-semibold">
                            <AlertCircle className="size-3.5 shrink-0" />
                            <span>{mismatchesCount} divergence(s) détectée(s) entre le document et la saisie client</span>
                          </div>
                        )}

                        {hasExtracted && (
                          <div className="flex flex-wrap gap-1.5 pt-0.5">
                            {Object.entries(doc.ai_extracted_fields!).map(([k, v]) => (
                              <span
                                key={k}
                                className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-white border border-[#E0E4E9] text-[10px] text-[#0C1825]"
                              >
                                <strong className="text-[#3D5166]">{k.replace(/_/g, ' ')} :</strong>
                                <span className="font-mono font-medium">{String(v)}</span>
                              </span>
                            ))}
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          ) : (
            <p className="text-xs text-[#3D5166]">Aucune pièce jointe transmise pour ce dossier.</p>
          )}
        </div>
      </div>

      {/* ── Decision Actions Bar ── */}
      {canDecide && (
        <div className="figma-card p-6 bg-white space-y-4 border-t-4 border-t-[#C0272D]">
          <div className="flex items-center gap-2">
            <ShieldCheck className="size-5 text-[#C0272D]" />
            <h3 className="font-display text-lg font-light text-[#0C1825]">
              Décision & Traitement du Dossier
            </h3>
          </div>
          <p className="text-xs text-[#3D5166]">
            En validant ce dossier, vous confirmez l&apos;accord technique de la BTS Bank et déclenchez l&apos;ouverture de la prise de rendez-vous en agence pour le client.
          </p>

          <div className="flex flex-wrap items-center gap-3 pt-2">
            <button
              type="button"
              onClick={() => setApproveOpen(true)}
              disabled={working}
              className="btn-red text-xs inline-flex items-center gap-1.5 shadow-xs"
              style={{ padding: '10px 24px' }}
            >
              <CheckCircle2 className="size-4" />
              <span>Valider l&apos;accord technique</span>
            </button>

            <button
              type="button"
              onClick={() => setRejectOpen(true)}
              disabled={working}
              className="btn-outline text-xs text-red-600 hover:bg-red-50 hover:border-red-300"
            >
              <XCircle className="size-4 text-red-600" />
              <span>Refuser le dossier</span>
            </button>

            {onCancel && (
              <button
                type="button"
                onClick={() => setCancelOpen(true)}
                disabled={working}
                className="btn-outline text-xs ml-auto"
              >
                Annuler
              </button>
            )}
          </div>
        </div>
      )}

      {/* ── Dialog 1: Approve Confirmation ── */}
      <Dialog open={approveOpen} onOpenChange={setApproveOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="font-display text-xl text-[#0C1825]">
              Confirmer l&apos;accord technique ?
            </DialogTitle>
            <DialogDescription className="text-xs text-[#3D5166] pt-2 leading-relaxed">
              Cette décision validera le financement demandé ({formattedAmount}) et débloquera automatiquement la planification de rendez-vous en agence pour le demandeur.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2 pt-4">
            <button
              type="button"
              onClick={() => setApproveOpen(false)}
              className="btn-outline text-xs"
            >
              Annuler
            </button>
            <button
              type="button"
              onClick={() => {
                setApproveOpen(false);
                onApprove();
              }}
              disabled={working}
              className="btn-red text-xs inline-flex items-center gap-1.5"
            >
              <CheckCircle2 className="size-4" />
              <span>Confirmer l&apos;accord</span>
            </button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Dialog 2: Reject Modal ── */}
      <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="font-display text-xl text-red-700">
              Refuser cette demande de crédit
            </DialogTitle>
            <DialogDescription className="text-xs text-[#3D5166] pt-2">
              Veuillez préciser le motif institutionnel du refus (qui sera communiqué au demandeur) :
            </DialogDescription>
          </DialogHeader>
          <div className="py-2">
            <textarea
              rows={3}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Ex: Capacité de remboursement insuffisante, pièces manquantes..."
              className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg p-3 text-[#0C1825] focus:outline-none focus:border-[#C0272D]"
              required
            />
          </div>
          <DialogFooter className="gap-2 pt-2">
            <button
              type="button"
              onClick={() => setRejectOpen(false)}
              className="btn-outline text-xs"
            >
              Annuler
            </button>
            <button
              type="button"
              onClick={() => {
                if (!reason.trim()) return;
                setRejectOpen(false);
                onReject(reason);
              }}
              disabled={working || !reason.trim()}
              className="btn-red text-xs bg-red-700 hover:bg-red-800"
            >
              Confirmer le refus
            </button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Dialog 3: Cancel Modal ── */}
      {onCancel && (
        <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle className="font-display text-xl text-[#0C1825]">
                Annuler ce dossier ?
              </DialogTitle>
              <DialogDescription className="text-xs text-[#3D5166] pt-2">
                Le dossier sera marqué comme annulé et passera en discussion avec le client.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter className="gap-2 pt-4">
              <button
                type="button"
                onClick={() => setCancelOpen(false)}
                className="btn-outline text-xs"
              >
                Retour
              </button>
              <button
                type="button"
                onClick={() => {
                  setCancelOpen(false);
                  onCancel();
                }}
                disabled={working}
                className="btn-red text-xs"
              >
                Confirmer l&apos;annulation
              </button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}

      {/* ── Document Preview & AI OCR Modal ── */}
      <StaffDocumentPreviewModal
        applicationId={application.id}
        document={previewDoc}
        open={previewOpen}
        onOpenChange={setPreviewOpen}
      />
    </div>
  );
}

function ReviewItem({
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

function statusToBadgeClass(status: string) {
  switch (status) {
    case 'SUBMITTED':
      return 'badge-warning';
    case 'STAFF_APPROVED':
    case 'APPROVED':
    case 'APPOINTMENT_CONFIRMED':
      return 'badge-success';
    case 'REJECTED':
    case 'STAFF_REJECTED':
    case 'CANCELLED':
      return 'badge-danger';
    default:
      return 'badge-neutral';
  }
}

function statusToFrench(status: string) {
  switch (status) {
    case 'SUBMITTED':
      return 'En attente d’instruction';
    case 'STAFF_APPROVED':
      return 'Accordé par le conseiller';
    case 'APPROVED':
      return 'Validé définitif';
    case 'REJECTED':
    case 'STAFF_REJECTED':
      return 'Rejeté';
    case 'CANCELLED':
      return 'Annulé';
    case 'APPOINTMENT_PROPOSED':
      return 'Rendez-vous proposé';
    case 'APPOINTMENT_CONFIRMED':
      return 'Rendez-vous confirmé';
    case 'APPOINTMENT_LOCKED':
      return 'Rendez-vous bloqué';
    default:
      return status;
  }
}
