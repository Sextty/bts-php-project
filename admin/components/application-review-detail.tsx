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
  Download,
  FileText,
  FileImage,
  File,
  User,
  CreditCard,
  Briefcase,
  MapPin,
  Calendar,
  CheckCircle2,
  XCircle,
  Ban,
  Shield,
  Loader2,
  Eye,
} from 'lucide-react';
import { ErrorAlert } from '@/components/error-alert';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { StaffApplicationDto } from '@/lib/api/staff';
import { statusLabel, statusColor } from '@/lib/status-labels';
import { downloadStaffDocument } from '@/lib/api/documents';
import { getStaffToken } from '@/lib/auth/staff-token';

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
  const [reason, setReason] = useState('');

  return (
    <div className="space-y-6">
      <ErrorAlert message={error} />

      {/* ── Page Title ── */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-[#0C1825]">
            Dossier {application.credit_request?.n_demande ?? `#${application.id}`}
          </h1>
          <div className="flex items-center gap-2 mt-1">
            <span className={`inline-flex items-center text-[10px] font-bold px-2.5 py-1 rounded-lg border ${statusColor(application.status)}`}>
              {statusLabel(application.status)}
            </span>
            {application.submitted_at && (
              <span className="text-[11px] text-[#3D5166]">
                Soumis le {new Date(application.submitted_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}
              </span>
            )}
          </div>
        </div>
      </div>

      {/* ── Two-Column Layout ── */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left Column — Application Data */}
        <div className="lg:col-span-2 space-y-5">
          {/* Applicant Card */}
          <SectionCard icon={User} title="Demandeur" color="text-blue-600" bgColor="bg-blue-50">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <DetailRow label="Nom complet" value={application.applicant?.name ?? '—'} />
              <DetailRow label="Email" value={application.applicant?.email ?? '—'} />
              <DetailRow label="Téléphone" value={application.applicant?.phone ?? '—'} />
              <DetailRow label="N° Demande" value={application.credit_request?.n_demande ?? '—'} />
            </div>
          </SectionCard>

          {/* Client Card */}
          {application.client && (
            <SectionCard icon={CreditCard} title="Informations Client" color="text-emerald-600" bgColor="bg-emerald-50">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <DetailRow label="Nom" value={`${application.client.prenom} ${application.client.nom}`} />
                <DetailRow label="Code client" value={application.client.code_client} />
                <DetailRow label="Type de pièce" value={`${application.client.type_pid} — ${application.client.numero_pid}`} />
                <DetailRow label="Profession" value={application.client.profession} />
              </div>
            </SectionCard>
          )}

          {/* Credit Request Card */}
          {application.credit_request && (
            <SectionCard icon={Briefcase} title="Demande de Crédit" color="text-purple-600" bgColor="bg-purple-50">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <DetailRow label="Type" value={application.credit_request.type_demande} />
                <DetailRow
                  label="Montant sollicité"
                  value={`${Number(application.credit_request.montant_global_sollicite).toLocaleString('fr-FR')} ${application.credit_request.code_devise}`}
                  bold
                />
              </div>
            </SectionCard>
          )}

          {/* Project Card */}
          {application.project && (
            <SectionCard icon={MapPin} title="Descriptif du Projet" color="text-amber-600" bgColor="bg-amber-50">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <DetailRow label="Type de projet" value={application.project.type_projet} />
                <DetailRow label="Coût" value={application.project.cout} />
                <DetailRow label="Financement" value={application.project.financement} />
                <DetailRow label="Revenus estimés" value={application.project.revenus} />
              </div>
            </SectionCard>
          )}
        </div>

        {/* Right Column — Documents, Appointment, Decision */}
        <div className="space-y-5">
          {/* Documents Card */}
          {application.documents && application.documents.length > 0 && (
            <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs overflow-hidden">
              <div className="flex items-center justify-between px-5 py-4 border-b border-[#E0E4E9]">
                <h3 className="text-sm font-bold text-[#0C1825] flex items-center gap-2">
                  <FileText className="size-4 text-[#C0272D]" />
                  Pièces Justificatives
                </h3>
                <span className="text-[10px] font-mono font-bold text-[#3D5166] bg-[#F4F6F8] px-2 py-0.5 rounded border border-[#E0E4E9]">
                  {application.documents.length} fichier{application.documents.length > 1 ? 's' : ''}
                </span>
              </div>
              <ul className="divide-y divide-[#F4F6F8]">
                {application.documents.map((doc) => {
                  const isImg = doc.mime_type.startsWith('image/');
                  const isPdf = doc.mime_type === 'application/pdf';
                  const Icon = isImg ? FileImage : isPdf ? FileText : File;
                  const downloadUrl = downloadStaffDocument(application.id, doc.id);
                  const token = typeof window !== 'undefined' ? getStaffToken() : null;

                  return (
                    <li key={doc.id} className="px-5 py-3 space-y-2">
                      <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2.5 min-w-0">
                          <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center shrink-0">
                            <Icon className="size-3.5" />
                          </div>
                          <div className="min-w-0">
                            <p className="text-xs font-semibold text-[#0C1825] truncate">{doc.original_filename}</p>
                            <div className="flex items-center gap-1.5 mt-0.5">
                              <AiVerificationBadge isValid={doc.ai_is_valid} verifiedAt={doc.ai_verified_at} />
                              <span className="text-[10px] text-[#3D5166] font-mono">
                                {doc.document_type.toUpperCase()}
                              </span>
                            </div>
                          </div>
                        </div>
                        <a
                          href={`${downloadUrl}?token=${token ?? ''}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="size-7 rounded-lg flex items-center justify-center text-[#3D5166] hover:text-[#C0272D] hover:bg-[#FDF2F2] transition-colors shrink-0"
                          title="Télécharger"
                        >
                          <Download className="size-3.5" />
                        </a>
                      </div>
                      {doc.ai_comment && (
                        <p className="text-[11px] text-[#3D5166] pl-9">
                          IA ({doc.ai_confidence}) : {doc.ai_comment}
                        </p>
                      )}
                      {doc.ai_mismatches && doc.ai_mismatches.length > 0 && (
                        <ul className="space-y-1 pl-9">
                          {doc.ai_mismatches.map((mismatch, i) => (
                            <li key={i} className="flex items-start gap-2 text-[11px]">
                              <span className={`inline-flex shrink-0 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase border ${
                                mismatch.severity === 'critical' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-amber-50 text-amber-700 border-amber-200'
                              }`}>
                                {mismatch.severity}
                              </span>
                              <span className="text-[#3D5166]">
                                <span className="font-semibold text-[#0C1825]">{mismatchFieldLabel(mismatch.field)}</span>
                                {' : attendu « '}{mismatch.expected ?? '—'}{' », trouvé « '}{mismatch.extracted ?? '—'}{' »'}
                              </span>
                            </li>
                          ))}
                        </ul>
                      )}
                    </li>
                  );
                })}
              </ul>
            </div>
          )}

          {/* Appointment Card */}
          {application.latest_appointment && (
            <SectionCard icon={Calendar} title="Rendez-vous & Agence" color="text-cyan-600" bgColor="bg-cyan-50">
              <div className="space-y-3">
                <DetailRow
                  label="Agence"
                  value={application.latest_appointment.branch?.name ?? application.branch?.name ?? '—'}
                />
                <DetailRow
                  label="Gouvernorat"
                  value={application.latest_appointment.branch?.governorate ?? application.branch?.ville ?? '—'}
                />
                <DetailRow
                  label="Date"
                  value={formatAppointmentDate(application.latest_appointment.scheduled_date)}
                />
                <DetailRow
                  label="Heure"
                  value={application.latest_appointment.scheduled_time.slice(0, 5)}
                />
                <div>
                  <p className="text-[10px] text-[#3D5166] uppercase tracking-wider font-bold mb-1">Statut</p>
                  <span className="inline-flex items-center text-[10px] font-bold px-2 py-0.5 rounded-lg border bg-blue-50 text-blue-700 border-blue-200 capitalize">
                    {application.latest_appointment.status}
                  </span>
                </div>
              </div>

              {application.latest_appointment.is_auto_scheduled_future && (
                <Alert className="mt-3 border-amber-500/30 bg-amber-500/10 text-amber-950">
                  <AlertCircle className="size-4 text-amber-600" />
                  <AlertDescription className="text-[11px] font-medium">
                    Programmé à une date ultérieure car la capacité quotidienne de l'agence est atteinte.
                  </AlertDescription>
                </Alert>
              )}
            </SectionCard>
          )}

          {/* ── Decision Panel ── */}
          <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs p-5 space-y-4">
            <h3 className="text-sm font-bold text-[#0C1825] flex items-center gap-2">
              <Shield className="size-4 text-[#C0272D]" />
              Décision Administrative
            </h3>

            {!canDecide ? (
              <div className={`p-4 rounded-xl border ${
                application.status.endsWith('REJECTED') || application.status === 'CANCELLED'
                  ? 'bg-red-50/50 border-red-200'
                  : 'bg-emerald-50/50 border-emerald-200'
              }`}>
                <div className="flex items-center gap-2 mb-1">
                  <span className={`inline-flex items-center text-[10px] font-bold px-2.5 py-1 rounded-lg border ${statusColor(application.status)}`}>
                    {statusLabel(application.status)}
                  </span>
                </div>
                {application.rejection_reason && (
                  <p className="mt-2 text-xs text-[#3D5166]">
                    <span className="font-semibold text-[#0C1825]">Motif :</span> {application.rejection_reason}
                  </p>
                )}
              </div>
            ) : (
              <div className="space-y-3">
                <Button
                  onClick={onApprove}
                  disabled={working}
                  className="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold"
                >
                  {working ? (
                    <><Loader2 className="size-4 animate-spin mr-2" /> Traitement…</>
                  ) : (
                    <><CheckCircle2 className="size-4 mr-2" /> Approuver le dossier</>
                  )}
                </Button>

                <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
                  <DialogTrigger render={
                    <Button variant="outline" disabled={working} className="w-full border-red-200 text-red-700 hover:bg-red-50">
                      <XCircle className="size-4 mr-2" /> Rejeter le dossier
                    </Button>
                  } />
                  <DialogContent>
                    <DialogHeader>
                      <DialogTitle>Confirmer le rejet</DialogTitle>
                      <DialogDescription>
                        Indiquez le motif du rejet — il sera enregistré dans le dossier et visible par le demandeur.
                      </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                      <Label htmlFor="reason">Motif du rejet</Label>
                      <Textarea
                        id="reason"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        rows={4}
                        required
                        minLength={3}
                        placeholder="Ex: Revenus insuffisants pour le montant demandé…"
                        aria-describedby="reason-hint"
                      />
                      <p id="reason-hint" className="text-[11px] text-[#3D5166]">
                        Minimum 3 caractères. Ce motif est enregistré et visible par le client.
                      </p>
                    </div>
                    <DialogFooter>
                      <Button variant="outline" onClick={() => setRejectOpen(false)}>
                        Annuler
                      </Button>
                      <Button
                        variant="destructive"
                        disabled={working || reason.trim().length < 3}
                        onClick={() => onReject(reason)}
                      >
                        <AlertTriangle className="size-4 mr-1" />
                        {working ? 'Rejet en cours…' : 'Confirmer le rejet'}
                      </Button>
                    </DialogFooter>
                  </DialogContent>
                </Dialog>

                {onCancel && (
                  <Button
                    variant="outline"
                    disabled={working}
                    onClick={onCancel}
                    className="w-full border-[#E0E4E9] text-[#3D5166] hover:bg-red-50 hover:text-red-700 hover:border-red-200"
                  >
                    <Ban className="size-4 mr-2" />
                    Annuler la demande
                  </Button>
                )}
              </div>
            )}
          </div>

          {/* Discussion Link for Cancelled apps */}
          {application.status === 'CANCELLED' && (
            <Link
              href={`/reports?application=${application.id}`}
              className="flex items-center justify-center gap-2 w-full rounded-xl border border-[#E0E4E9] bg-white px-4 py-3 text-sm font-semibold text-[#3D5166] hover:text-[#C0272D] hover:border-[#C0272D]/30 transition-colors shadow-xs"
            >
              <MessageSquare className="size-4" />
              Ouvrir la discussion
            </Link>
          )}
        </div>
      </div>
    </div>
  );
}

// ─── Sub-Components ──────────────────────────────

function SectionCard({
  icon: Icon,
  title,
  color,
  bgColor,
  children,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  color: string;
  bgColor: string;
  children: React.ReactNode;
}) {
  return (
    <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs overflow-hidden">
      <div className="flex items-center gap-2.5 px-5 py-4 border-b border-[#E0E4E9]">
        <div className={`size-7 rounded-lg ${bgColor} ${color} flex items-center justify-center`}>
          <Icon className="size-4" />
        </div>
        <h3 className="text-sm font-bold text-[#0C1825]">{title}</h3>
      </div>
      <div className="px-5 py-4">{children}</div>
    </div>
  );
}

function DetailRow({ label, value, bold = false }: { label: string; value: string; bold?: boolean }) {
  return (
    <div>
      <p className="text-[10px] text-[#3D5166] uppercase tracking-wider font-bold">{label}</p>
      <p className={`text-sm ${bold ? 'font-bold text-[#0C1825]' : 'font-medium text-[#0C1825]'} mt-0.5`}>{value}</p>
    </div>
  );
}

function AiVerificationBadge({ isValid, verifiedAt }: { isValid: boolean | null; verifiedAt: string | null }) {
  if (!verifiedAt) {
    return (
      <span className="inline-flex items-center gap-0.5 text-[9px] font-bold text-[#3D5166] bg-[#F4F6F8] px-1.5 py-0.5 rounded border border-[#E0E4E9]">
        <CircleHelp className="size-2.5" /> Non vérifié
      </span>
    );
  }
  if (isValid) {
    return (
      <span className="inline-flex items-center gap-0.5 text-[9px] font-bold text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200">
        <CircleCheck className="size-2.5" /> Vérifié IA
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-0.5 text-[9px] font-bold text-red-700 bg-red-50 px-1.5 py-0.5 rounded border border-red-200">
      <CircleX className="size-2.5" /> Non valide
    </span>
  );
}

const MISMATCH_FIELD_LABELS: Record<string, string> = {
  nom: 'Nom',
  prenom: 'Prénom',
  date_naissance: 'Date de naissance',
  numero_pid: "N° de la pièce d'identité",
  date_delivrance_pid: 'Date de délivrance',
  numero_carte_sejour: 'N° carte de séjour',
};

function mismatchFieldLabel(field: string): string {
  return MISMATCH_FIELD_LABELS[field] ?? field;
}

function formatAppointmentDate(dateStr: string): string {
  try {
    const d = new Date(dateStr);
    return d.toLocaleDateString('fr-FR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  } catch {
    return dateStr;
  }
}
