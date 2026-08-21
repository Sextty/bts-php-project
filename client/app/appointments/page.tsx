'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  Calendar,
  Clock,
  MapPin,
  Building2,
  Lock,
  CheckCircle2,
  AlertCircle,
  CalendarCheck2,
  ArrowRight,
  MessageCircle,
  FileText,
  ShieldAlert,
  Info,
  Layers,
  Sparkles,
} from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
import { Breadcrumbs, BackLink } from '@/components/breadcrumbs';
import { ErrorAlert } from '@/components/error-alert';
import { PageLoading } from '@/components/page-loading';
import { StatusBadge } from '@/components/status-badge';
import { statusLabel } from '@/lib/status-labels';
import {
  listApplications,
  type CreditApplicationDto,
  type ApplicationStatus,
} from '@/lib/api/credit-applications';
import {
  getAppointment,
  acceptAppointment,
  rejectAppointment,
  type AppointmentDto,
} from '@/lib/api/appointments';
import {
  getReportMessages,
  sendReportMessageWithAttachment,
  type ReportMessageDto,
} from '@/lib/api/reports';
import { ReportChat } from '@/components/report-chat';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

const ACCEPTED_STATUSES: ApplicationStatus[] = [
  'APPROVED',
  'STAFF_APPROVED',
  'APPOINTMENT_PROPOSED',
  'APPOINTMENT_CONFIRMED',
  'APPOINTMENT_LOCKED',
];

function formatDate(dateStr: string) {
  try {
    return new Date(dateStr).toLocaleDateString('fr-FR', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  } catch {
    return dateStr;
  }
}

function formatTime(timeStr: string) {
  return timeStr ? timeStr.slice(0, 5) : '';
}

export default function AppointmentsPage() {
  const router = useRouter();

  const [applications, setApplications] = useState<CreditApplicationDto[]>([]);
  const [selectedAppId, setSelectedAppId] = useState<number | null>(null);
  const [appointment, setAppointment] = useState<AppointmentDto | null>(null);

  const [selectedBranch, setSelectedBranch] = useState<string>('Agence BTS Tunis');
  const [preferredDate, setPreferredDate] = useState<string>('');
  const [preferredTimeSlot, setPreferredTimeSlot] = useState<string>('09:30');

  const [reportMessages, setReportMessages] = useState<ReportMessageDto[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [appointmentLoading, setAppointmentLoading] = useState(false);
  const [actionWorking, setActionWorking] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [rejectOpen, setRejectOpen] = useState(false);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }

    listApplications()
      .then((res) => {
        const rawApps = Array.isArray(res)
          ? res
          : res && typeof res === 'object' && Array.isArray((res as Record<string, unknown>).applications)
          ? (res as { applications: CreditApplicationDto[] }).applications
          : [];

        setApplications(rawApps);

        // Find eligible accepted application
        const eligible = rawApps.filter((a) => ACCEPTED_STATUSES.includes(a.status));
        if (eligible.length > 0) {
          const firstEligible = eligible[0];
          setSelectedAppId(firstEligible.id);
          if (firstEligible.branch?.name) {
            setSelectedBranch(firstEligible.branch.name);
          }
        }
      })
      .catch((err) => {
        setError(err instanceof ApiError ? err.message : 'Impossible de charger vos demandes.');
      })
      .finally(() => setLoading(false));
  }, [router]);

  // Load appointment details and report messages when selected application changes
  useEffect(() => {
    if (!selectedAppId) {
      setAppointment(null);
      setReportMessages(null);
      return;
    }

    setAppointmentLoading(true);
    Promise.allSettled([
      getAppointment(selectedAppId).then(({ appointment }) => {
        setAppointment(appointment);
        if (appointment.branch?.name) {
          setSelectedBranch(appointment.branch.name);
        }
      }),
      getReportMessages(selectedAppId).then(({ messages }) => {
        setReportMessages(messages);
      }),
    ]).finally(() => setAppointmentLoading(false));
  }, [selectedAppId]);

  async function handleAccept() {
    if (!selectedAppId) return;
    setError(null);
    setActionWorking(true);
    try {
      const { appointment } = await acceptAppointment(selectedAppId);
      setAppointment(appointment);
      // update local application status
      setApplications((prev) =>
        prev.map((a) => (a.id === selectedAppId ? { ...a, status: 'APPOINTMENT_CONFIRMED' } : a))
      );
      getReportMessages(selectedAppId)
        .then(({ messages }) => setReportMessages(messages))
        .catch(() => {});
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Une erreur est survenue lors de la confirmation.');
    } finally {
      setActionWorking(false);
    }
  }

  async function handleReject() {
    if (!selectedAppId) return;
    setError(null);
    setActionWorking(true);
    try {
      const { application_status, appointment } = await rejectAppointment(selectedAppId);
      setAppointment(appointment);
      setRejectOpen(false);
      setApplications((prev) =>
        prev.map((a) =>
          a.id === selectedAppId ? { ...a, status: application_status as ApplicationStatus } : a
        )
      );
      if (application_status === 'APPOINTMENT_LOCKED') {
        getReportMessages(selectedAppId)
          .then(({ messages }) => setReportMessages(messages))
          .catch(() => {});
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Une erreur est survenue lors du changement de créneau.');
    } finally {
      setActionWorking(false);
    }
  }

  if (loading) return <PageLoading />;

  const eligibleApplications = applications.filter((a) => ACCEPTED_STATUSES.includes(a.status));
  const activeSelectedApp = applications.find((a) => a.id === selectedAppId);
  const selectedApp = activeSelectedApp;
  const isAccepted = eligibleApplications.length > 0;
  const isAppointmentConfirmed = appointment?.status === 'accepted' || (activeSelectedApp?.status as string) === 'APPOINTMENT_CONFIRMED';
  const isLockedOut = (activeSelectedApp?.status as string) === 'APPOINTMENT_LOCKED';
  const maxAttempts = appointment?.max_attempts ?? 5;
  const currentAttempt = appointment?.attempt_number ?? 1;
  const remainingChances = Math.max(0, maxAttempts - currentAttempt);

  return (
    <div className="min-h-screen bg-[#F4F6F8] text-[#1E2D3D]">
      <DashboardHeader />
      <main id="main" className="mx-auto max-w-5xl px-4 sm:px-8 py-8 sm:py-10 space-y-6">
        <BackLink href="/dashboard" label="Retour au tableau de bord" />
        <Breadcrumbs
          items={[
            { label: 'Tableau de bord', href: '/dashboard' },
            { label: 'Prise de rendez-vous en agence' },
          ]}
          className="mb-2"
        />

        {/* ── Page Header ── */}
        <div className="border-b border-[#E0E4E9] pb-4">
          <p className="overline">Planification & Entretien Bancaire</p>
          <h1 className="font-display text-3xl font-light text-[#0C1825]">
            Prise de Rendez-vous en Agence BTS
          </h1>
          <p className="text-xs text-[#3D5166] mt-1">
            Réservation de créneau d&apos;entretien avec votre conseiller pour l&apos;instruction définitive et la signature de votre contrat de prêt.
          </p>
        </div>

        <ErrorAlert message={error} />

        {/* ════════════════════════════════════════════════════════════════════
            CASE 1: ZERO APPLICATIONS DEPOSITED (ZONE STRICTEMENT BLOQUÉE)
           ════════════════════════════════════════════════════════════════════ */}
        {applications.length === 0 && (
          <div className="figma-card p-8 sm:p-12 bg-white text-center space-y-5 border-t-4 border-t-[#C0272D] shadow-xs">
            <div className="size-16 rounded-full bg-[#FDF2F2] text-[#C0272D] mx-auto flex items-center justify-center border border-[#FECACA]">
              <Lock className="size-8" />
            </div>
            <div className="space-y-2 max-w-md mx-auto">
              <span className="badge badge-danger text-xs">Accès Verrouillé</span>
              <h2 className="font-display text-2xl font-light text-[#0C1825]">
                Zone de Rendez-vous Non Accessible
              </h2>
              <p className="text-xs text-[#3D5166] leading-relaxed">
                Vous n&apos;avez actuellement aucune demande de crédit enregistrée. La prise de rendez-vous en agence pour l&apos;entretien technique et la signature n&apos;est accessible <strong>qu&apos;après acceptation ou accord préalable</strong> de votre dossier.
              </p>
            </div>
            <div className="pt-2">
              <Link
                href="/applications"
                className="btn-red text-xs inline-flex items-center gap-2 shadow-xs"
                style={{ padding: '10px 24px' }}
              >
                <FileText className="size-4" />
                <span>Déposer ma première demande de crédit</span>
                <ArrowRight className="size-4" />
              </Link>
            </div>
          </div>
        )}

        {/* ════════════════════════════════════════════════════════════════════
            CASE 2: APPLICATIONS EXIST, BUT NOT YET ACCEPTED (ZONE BLOQUÉE)
           ════════════════════════════════════════════════════════════════════ */}
        {applications.length > 0 && !isAccepted && (
          <div className="space-y-6">
            <div className="figma-card p-8 bg-white text-center space-y-4 border-t-4 border-t-amber-500 shadow-xs">
              <div className="size-16 rounded-full bg-amber-50 text-amber-600 mx-auto flex items-center justify-center border border-amber-200">
                <Clock className="size-8" />
              </div>
              <div className="space-y-2 max-w-lg mx-auto">
                <span className="badge badge-warning text-xs">En Cours d&apos;Instruction</span>
                <h2 className="font-display text-2xl font-light text-[#0C1825]">
                  Dossier en Attente d&apos;Accord Bancaire
                </h2>
                <p className="text-xs text-[#3D5166] leading-relaxed">
                  Votre demande de financement est actuellement examinée par nos équipes. La sélection d&apos;agence et la fixation de la date d&apos;entretien <strong>seront automatiquement débloquées dès l&apos;acceptation technique de votre crédit</strong>.
                </p>
              </div>
            </div>

            {/* List of Pending Applications */}
            <div className="figma-card p-6 bg-white space-y-4">
              <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-3">
                <h3 className="text-xs font-bold uppercase tracking-wider text-[#0C1825] flex items-center gap-2">
                  <FileText className="size-4 text-[#C0272D]" /> Vos Dossiers en Cours d&apos;Étude
                </h3>
              </div>

              <div className="space-y-3">
                {applications.map((app) => (
                  <div
                    key={app.id}
                    className="p-4 rounded-xl border border-[#E0E4E9] bg-[#F4F6F8] flex flex-col sm:flex-row sm:items-center justify-between gap-3"
                  >
                    <div className="space-y-0.5">
                      <p className="font-bold text-xs text-[#0C1825]">
                        {app.credit_request?.n_demande ?? `Dossier #${app.id}`}
                      </p>
                      <p className="text-[11px] text-[#3D5166]">
                        {app.credit_request?.type_demande || 'Crédit BTS'} · Montant sollicité :{' '}
                        <strong className="text-[#0C1825]">
                          {app.credit_request?.montant_global_sollicite || '—'} TND
                        </strong>
                      </p>
                    </div>

                    <div className="flex items-center gap-3">
                      <StatusBadge status={app.status} label={statusLabel(app.status)} />
                      <Link
                        href={`/applications/${app.id}`}
                        className="btn-outline text-xs inline-flex items-center gap-1"
                        style={{ padding: '6px 12px' }}
                      >
                        <span>Suivi</span>
                        <ArrowRight className="size-3" />
                      </Link>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}

        {/* ════════════════════════════════════════════════════════════════════
            CASE 3: CREDIT ACCEPTED / ELIGIBLE (ZONE OUVERTE ET DÉBLOQUÉE)
           ════════════════════════════════════════════════════════════════════ */}
        {/* ════════════════════════════════════════════════════════════════════
            CASE 3: ALL CHANCES EXHAUSTED (LOCKED OUT -> DIRECT LIVE CHAT OPEN)
           ════════════════════════════════════════════════════════════════════ */}
        {isAccepted && isLockedOut && (
          <div className="space-y-6">
            <div className="figma-card p-6 sm:p-8 bg-white border-t-4 border-t-amber-500 shadow-xs space-y-4">
              <div className="flex items-center gap-3">
                <div className="size-10 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center border border-amber-200 shrink-0">
                  <AlertCircle className="size-5" />
                </div>
                <div>
                  <span className="badge badge-warning text-[10px]">Planification personnalisée requise</span>
                  <h3 className="font-display text-xl font-light text-[#0C1825]">
                    Toutes les propositions automatiques ont été épuisées ({maxAttempts}/{maxAttempts})
                  </h3>
                </div>
              </div>
              <p className="text-xs text-[#3D5166] leading-relaxed">
                Vous avez utilisé toutes vos possibilités de modification automatique de créneau. Votre dossier est désormais affecté à votre conseiller bancaire de l&apos;agence <strong>{selectedBranch}</strong>. L&apos;espace de discussion directe ci-dessous est <strong>ouvert</strong> pour convenir ensemble d&apos;un rendez-vous sur mesure.
              </p>
            </div>

            {/* Direct Embedded ReportChat */}
            <div className="figma-card p-6 bg-white shadow-xs space-y-4">
              <div className="border-b border-[#E0E4E9] pb-3 flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <MessageCircle className="size-4 text-[#C0272D]" />
                  <h3 className="text-sm font-bold text-[#0C1825]">
                    Discussion directe avec votre conseiller BTS
                  </h3>
                </div>
                <span className="text-[11px] text-[#3D5166] font-medium">
                  {selectedBranch}
                </span>
              </div>
              <ReportChat
                applicationId={selectedAppId!}
                currentSenderType="customer"
                getToken={getToken}
                initialMessages={reportMessages ?? []}
                onSend={async (body, file) => {
                  const { message } = await sendReportMessageWithAttachment(selectedAppId!, body, file);
                  return message;
                }}
              />
            </div>
          </div>
        )}

        {/* ════════════════════════════════════════════════════════════════════
            CASE 4: CREDIT ACCEPTED / ELIGIBLE (PROPOSED OR CONFIRMED)
           ════════════════════════════════════════════════════════════════════ */}
        {isAccepted && !isLockedOut && (
          <div className="space-y-6">
            {/* Success Unlocked Banner */}
            <div className="figma-card p-6 bg-gradient-to-r from-emerald-900 to-[#0C1825] text-white space-y-3 relative overflow-hidden">
              <div className="flex items-center gap-2.5">
                <div className="size-8 rounded-full bg-emerald-500/20 text-emerald-300 flex items-center justify-center">
                  <CheckCircle2 className="size-5" />
                </div>
                <div>
                  <span className="text-[10px] font-bold uppercase tracking-wider text-emerald-400">
                    Crédit Accepté · Prise de Rendez-vous Ouverte
                  </span>
                  <h2 className="font-display text-xl sm:text-2xl font-light text-white">
                    Félicitations ! Votre dossier a reçu un accord favorable.
                  </h2>
                </div>
              </div>
              <p className="text-xs text-white/80 max-w-2xl pl-10">
                Vous pouvez maintenant consulter votre agence régionale BTS de rattachement, confirmer la date et l&apos;heure de votre entretien d&apos;instruction ou demander un créneau alternatif.
              </p>
            </div>

            {/* Application Switcher (if multiple eligible applications) */}
            {eligibleApplications.length > 1 && (
              <div className="figma-card p-4 bg-white flex items-center justify-between gap-4">
                <span className="text-xs font-semibold text-[#0C1825]">Dossier concerné :</span>
                <select
                  value={selectedAppId ?? ''}
                  onChange={(e) => setSelectedAppId(Number(e.target.value))}
                  className="text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3 py-2 text-[#0C1825] focus:outline-none focus:border-[#C0272D]"
                >
                  {eligibleApplications.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.credit_request?.n_demande ?? `Dossier #${a.id}`} — {a.credit_request?.type_demande}
                    </option>
                  ))}
                </select>
              </div>
            )}

            {/* ── 1. Agence Régionale BTS Affectée ── */}
            <div className="figma-card p-6 sm:p-8 bg-white space-y-6">
              <div className="border-b border-[#E0E4E9] pb-4">
                <p className="overline">Agence BTS Affectée</p>
                <h3 className="font-display text-xl font-light text-[#0C1825] flex items-center gap-2">
                  <Building2 className="size-5 text-[#C0272D]" />
                  Agence Régionale BTS Bank
                </h3>
                <p className="text-xs text-[#3D5166] mt-1">
                  Votre dossier est affecté à l&apos;agence bancaire la plus proche de l&apos;implantation de votre projet.
                </p>
              </div>

              {selectedApp?.branch || appointment?.branch ? (
                <div className="p-4 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] flex items-start gap-3">
                  <MapPin className="size-5 text-[#C0272D] shrink-0 mt-0.5" />
                  <div className="text-xs space-y-1">
                    <p className="font-bold text-sm text-[#0C1825]">
                      {selectedApp?.branch?.name ?? appointment?.branch?.name}
                    </p>
                    <p className="text-[#3D5166]">
                      {selectedApp?.branch?.address ?? appointment?.branch?.address ?? 'Agence BTS'}
                      {selectedApp?.branch?.ville ? ` — ${selectedApp.branch.ville}` : ''}
                    </p>
                    <p className="text-[11px] text-[#3D5166]">
                      Horaires d&apos;accueil : Du Lundi au Vendredi, 08h00 – 16h30
                    </p>
                    {(selectedApp?.branch?.google_maps_url || appointment?.branch?.google_maps_url) && (
                      <a
                        href={selectedApp?.branch?.google_maps_url ?? appointment?.branch?.google_maps_url ?? '#'}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 text-[#C0272D] hover:underline font-semibold text-[11px] pt-1"
                      >
                        Voir l&apos;itinéraire sur Google Maps &rarr;
                      </a>
                    )}
                  </div>
                </div>
              ) : (
                <div className="p-4 bg-amber-50 rounded-xl border border-amber-200 text-xs text-amber-800 flex items-center gap-2">
                  <AlertCircle className="size-4 text-amber-600 shrink-0" />
                  <span>Agence non encore affectée (l&apos;affectation s&apos;effectue lors de la soumission du dossier).</span>
                </div>
              )}
            </div>

            {/* ── 2. Sélection de la Date & Heure de l'Entretien ── */}
            <div className="figma-card p-6 sm:p-8 bg-white space-y-6">
              <div className="border-b border-[#E0E4E9] pb-4">
                <p className="overline">Étape 2 · Date & Créneau</p>
                <h3 className="font-display text-xl font-light text-[#0C1825] flex items-center gap-2">
                  <Calendar className="size-5 text-[#C0272D]" />
                  Choix de la Date & Heure de l&apos;Entretien
                </h3>
                <p className="text-xs text-[#3D5166] mt-1">
                  Fixez le créneau de rendez-vous qui vous convient le mieux.
                </p>
              </div>

              {/* Appointment State: Already Proposed / Scheduled */}
              {appointment ? (
                <div className="space-y-5">
                  <div className="p-5 rounded-xl border border-[#E0E4E9] bg-white space-y-4 shadow-xs">
                    <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-3">
                      <div className="flex items-center gap-2">
                        <Calendar className="size-4 text-[#C0272D]" />
                        <span className="text-xs font-bold text-[#0C1825]">
                          {isAppointmentConfirmed ? 'Rendez-vous Confirmé' : 'Proposition de Rendez-vous'}
                        </span>
                      </div>
                      <div className="flex items-center gap-2">
                        <span className={`badge ${isAppointmentConfirmed ? 'badge-success' : 'badge-warning'} text-xs`}>
                          {isAppointmentConfirmed ? 'Rendez-vous Confirmé & Verrouillé' : `Proposition ${currentAttempt} / ${maxAttempts}`}
                        </span>
                        {!isAppointmentConfirmed && (
                          <span className="text-[11px] text-[#3D5166] font-medium hidden sm:inline">
                            (Max {maxAttempts} propositions)
                          </span>
                        )}
                      </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                      <div className="p-3.5 bg-[#F4F6F8] rounded-lg space-y-1">
                        <span className="text-[10px] uppercase font-bold text-[#3D5166] flex items-center gap-1">
                          <Calendar className="size-3 text-[#C0272D]" /> Date retenue
                        </span>
                        <p className="text-sm font-bold text-[#0C1825] capitalize">
                          {formatDate(appointment.scheduled_date)}
                        </p>
                      </div>

                      <div className="p-3.5 bg-[#F4F6F8] rounded-lg space-y-1">
                        <span className="text-[10px] uppercase font-bold text-[#3D5166] flex items-center gap-1">
                          <Clock className="size-3 text-[#C0272D]" /> Heure de l&apos;entretien
                        </span>
                        <p className="text-sm font-bold text-[#0C1825] font-mono">
                          {formatTime(appointment.scheduled_time)}
                        </p>
                      </div>
                    </div>
                  </div>

                  {/* Actions if proposed */}
                  {!isAppointmentConfirmed && (
                    <div className="space-y-2 pt-2">
                      <div className="flex flex-col sm:flex-row gap-3">
                        <button
                          type="button"
                          onClick={handleAccept}
                          disabled={actionWorking}
                          className="btn-red text-xs flex-1 inline-flex items-center justify-center gap-2 shadow-xs"
                          style={{ padding: '10px 24px' }}
                        >
                          <CalendarCheck2 className="size-4" />
                          <span>{actionWorking ? 'Confirmation…' : 'Confirmer ce rendez-vous (Fixe & Définitif)'}</span>
                        </button>

                        <button
                          type="button"
                          onClick={() => setRejectOpen(true)}
                          disabled={actionWorking || currentAttempt >= maxAttempts}
                          className="btn-outline text-xs inline-flex items-center justify-center gap-2"
                        >
                          {currentAttempt >= maxAttempts
                            ? `Nombre max de changements atteint (${maxAttempts}/${maxAttempts})`
                            : `Changer de créneau (${remainingChances} essai(s) restant(s))`}
                        </button>
                      </div>
                      <p className="text-[11px] text-[#3D5166] text-center sm:text-left">
                        * Une fois confirmé, le créneau ne pourra plus être modifié en ligne et ouvrira l&apos;espace de discussion directe avec votre agence.
                      </p>
                    </div>
                  )}

                  {/* Confirmed checklist and embedded chat */}
                  {isAppointmentConfirmed && (
                    <div className="space-y-6">
                      <div className="p-5 bg-emerald-50 border border-emerald-200 rounded-xl space-y-3 text-xs text-emerald-950">
                        <div className="flex items-center gap-2 font-bold text-emerald-900">
                          <CheckCircle2 className="size-4 text-emerald-600" />
                          <span>Votre entretien en agence est validé et verrouillé !</span>
                        </div>
                        <p>
                          Veuillez vous présenter à l&apos;agence <strong>{selectedBranch}</strong> muni des pièces justificatives originales :
                        </p>
                        <ul className="list-disc list-inside space-y-1 text-emerald-900 pl-2">
                          <li>Original de votre Carte d&apos;Identité Nationale (CIN)</li>
                          <li>Factures proforma / devis originaux</li>
                          <li>Justificatif de domicile récent</li>
                        </ul>
                      </div>

                      {/* Direct chat with advisor */}
                      <div className="p-6 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] space-y-4">
                        <div className="flex items-center gap-2 pb-2 border-b border-[#E0E4E9]">
                          <MessageCircle className="size-4 text-[#C0272D]" />
                          <h4 className="text-xs font-bold text-[#0C1825]">
                            Messagerie directe avec votre conseiller ({selectedBranch})
                          </h4>
                        </div>
                        <ReportChat
                          applicationId={selectedAppId!}
                          currentSenderType="customer"
                          getToken={getToken}
                          initialMessages={reportMessages ?? []}
                          onSend={async (body, file) => {
                            const { message } = await sendReportMessageWithAttachment(selectedAppId!, body, file);
                            return message;
                          }}
                        />
                      </div>
                    </div>
                  )}
                </div>
              ) : (
                /* No slot generated yet - manual date preference */
                <div className="space-y-5">
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div className="space-y-1.5">
                      <label htmlFor="pref_date" className="text-xs font-semibold text-[#0C1825]">
                        Date souhaitée <span className="text-[#C0272D]">*</span>
                      </label>
                      <input
                        id="pref_date"
                        type="date"
                        value={preferredDate}
                        onChange={(e) => setPreferredDate(e.target.value)}
                        min={new Date().toISOString().split('T')[0]}
                        className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3.5 py-2.5 text-[#0C1825] focus:outline-none focus:border-[#C0272D]"
                      />
                    </div>

                    <div className="space-y-1.5">
                      <label htmlFor="pref_time" className="text-xs font-semibold text-[#0C1825]">
                        Créneau horaire <span className="text-[#C0272D]">*</span>
                      </label>
                      <select
                        id="pref_time"
                        value={preferredTimeSlot}
                        onChange={(e) => setPreferredTimeSlot(e.target.value)}
                        className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3.5 py-2.5 text-[#0C1825] focus:outline-none focus:border-[#C0272D]"
                      >
                        <option value="09:00">09h00 – 10h00 (Matin)</option>
                        <option value="10:30">10h30 – 11h30 (Matin)</option>
                        <option value="14:00">14h00 – 15h00 (Après-midi)</option>
                        <option value="15:30">15h30 – 16h30 (Après-midi)</option>
                      </select>
                    </div>
                  </div>

                  <div className="p-4 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] text-xs text-[#3D5166] flex items-center gap-2.5">
                    <Info className="size-4 text-[#C0272D] shrink-0" />
                    <span>
                      Votre agence régionale validera ce créneau et vous notifiera par SMS / notification dans les 24h ouvrées.
                    </span>
                  </div>

                  <div className="pt-2 flex justify-end">
                    <Link
                      href={`/applications/${selectedAppId}/report`}
                      className="btn-red text-xs inline-flex items-center gap-2 shadow-xs"
                      style={{ padding: '10px 24px' }}
                    >
                      <CalendarCheck2 className="size-4" />
                      <span>Transmettre ma préférence de rendez-vous</span>
                    </Link>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* ── Decline / Change Slot Dialog ── */}
        <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle className="font-display text-xl text-[#0C1825]">
                Changer de créneau horaire ?
              </DialogTitle>
              <DialogDescription className="text-xs text-[#3D5166] pt-2 leading-relaxed">
                Si vous refusez ce créneau, un horaire alternatif vous sera immédiatement attribué (<strong>Proposition {currentAttempt + 1}/{maxAttempts}</strong>). Si toutes les propositions sont épuisées, l&apos;espace de messagerie directe avec votre conseiller vous permettra de fixer un rendez-vous personnalisé.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter className="gap-2 pt-4">
              <button
                type="button"
                onClick={() => setRejectOpen(false)}
                className="btn-outline text-xs"
              >
                Conserver ce créneau
              </button>
              <button
                type="button"
                onClick={handleReject}
                disabled={actionWorking}
                className="btn-red text-xs"
              >
                {actionWorking ? 'Changement en cours…' : 'Valider le changement de créneau'}
              </button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </main>
    </div>
  );
}
