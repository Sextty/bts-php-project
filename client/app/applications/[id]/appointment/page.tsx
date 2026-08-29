'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { CalendarCheck2, CheckCircle2, Lock, MapPin, MessageCircle, Calendar, Clock, Building2 } from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { Breadcrumbs, BackLink } from '@/components/breadcrumbs';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { getAppointment, acceptAppointment, rejectAppointment, type AppointmentDto } from '@/lib/api/appointments';
import { getApplication, type ApplicationStatus } from '@/lib/api/credit-applications';
import {
  getReportMessages,
  sendReportMessageWithAttachment,
  type ReportMessageDto,
} from '@/lib/api/reports';
import { ReportChat } from '@/components/report-chat';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { PageLoading } from '@/components/page-loading';

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleDateString('fr-FR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
}

function formatTime(timeStr: string) {
  return timeStr.slice(0, 5);
}

export default function AppointmentPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [applicationStatus, setApplicationStatus] = useState<ApplicationStatus | null>(null);
  const [applicationNumber, setApplicationNumber] = useState<string | null>(null);
  const [appointment, setAppointment] = useState<AppointmentDto | null>(null);
  const [reportMessages, setReportMessages] = useState<ReportMessageDto[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [rejectOpen, setRejectOpen] = useState(false);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }
    Promise.allSettled([
      getApplication(applicationId).then(({ application }) => {
        setApplicationStatus(application.status);
        setApplicationNumber(application.credit_request?.n_demande ?? null);
      }),
      getAppointment(applicationId).then(({ appointment }) => {
        setAppointment(appointment);
      }),
      getReportMessages(applicationId).then(({ messages }) => {
        setReportMessages(messages);
      }),
    ])
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Impossible de charger les informations.'))
      .finally(() => setLoading(false));
  }, [applicationId, router]);

  async function handleAccept() {
    setError(null);
    setWorking(true);
    try {
      const { appointment } = await acceptAppointment(applicationId);
      setAppointment(appointment);
      setApplicationStatus('APPOINTMENT_CONFIRMED');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Une erreur est survenue. Veuillez réessayer.');
    } finally {
      setWorking(false);
    }
  }

  async function handleReject() {
    setError(null);
    setWorking(true);
    try {
      const { application_status, appointment } = await rejectAppointment(applicationId);
      setApplicationStatus(application_status as ApplicationStatus);
      setAppointment(appointment);
      setRejectOpen(false);
      if (appointment?.remaining_reschedules === 0) {
        getReportMessages(applicationId)
          .then(({ messages }) => setReportMessages(messages))
          .catch(() => setReportMessages([]));
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Une erreur est survenue. Veuillez réessayer.');
    } finally {
      setWorking(false);
    }
  }

  if (loading) return <PageLoading />;

  const isConfirmed = appointment?.status === 'accepted' || applicationStatus === 'APPOINTMENT_CONFIRMED';
  const isLocked = applicationStatus === 'APPOINTMENT_LOCKED';
  const isCancelled = applicationStatus === 'CANCELLED';
  const maxReschedules = appointment?.max_reschedules ?? 4;
  const rescheduleCount = appointment?.reschedule_count ?? 0;
  const remainingChanges = appointment?.remaining_reschedules ?? maxReschedules;
  const isAtRescheduleLimit = Boolean(appointment && remainingChanges === 0);
  const remainingLabel = `${remainingChanges} changement${remainingChanges === 1 ? '' : 's'} restant${remainingChanges === 1 ? '' : 's'}`;

  return (
    <div className="portal-shell">
      <DashboardHeader />
      <main id="main" className="mx-auto max-w-4xl px-4 sm:px-8 py-8 sm:py-10 space-y-6">
        <BackLink href={`/applications/${applicationId}`} label="Retour aux détails" />
        <Breadcrumbs
          items={[
            { label: 'Tableau de bord', href: '/dashboard' },
            { label: 'Mes demandes', href: '/applications' },
            { label: applicationNumber ?? `Dossier #${applicationId}`, href: `/applications/${applicationId}` },
            { label: 'Rendez-vous en agence' },
          ]}
          className="mb-2"
        />

        <div className="border-b border-[#E0E4E9] pb-4">
          <p className="overline">Entretien & Signature</p>
          <h1 className="font-display text-3xl font-light text-[#0C1825]">
            Rendez-vous en Agence BTS Bank
          </h1>
          <p className="text-xs text-[#3D5166] mt-1">
            Confirmez votre présence ou demandez un réajustement de créneau avec votre conseiller.
          </p>
        </div>

        <ErrorAlert message={error} />

        {/* ── CASE 1: ALL CHANCES EXHAUSTED (LOCKED OUT) ── */}
        {isLocked && (
          <div className="space-y-6">
            <div className="figma-card p-6 sm:p-8 bg-white border-t-4 border-t-amber-500 shadow-xs space-y-4">
              <div className="flex items-center gap-3">
                <div className="size-10 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center border border-amber-200 shrink-0">
                  <Lock className="size-5" />
                </div>
                <div>
                  <span className="badge badge-warning text-[10px]">Planification personnalisée</span>
                  <h3 className="font-display text-xl font-light text-[#0C1825]">
                    Toutes les propositions automatiques ont été épuisées
                  </h3>
                </div>
              </div>
              <p className="text-xs text-[#3D5166] leading-relaxed">
                Vous avez utilisé toutes vos possibilités de modification automatique de créneau. Votre dossier est affecté à votre conseiller bancaire. L&apos;espace de discussion directe ci-dessous est <strong>ouvert</strong> pour fixer un créneau convenable.
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
                {appointment?.branch && (
                  <span className="text-[11px] text-[#3D5166] font-medium">
                    {appointment.branch.name}
                  </span>
                )}
              </div>
              <ReportChat
                applicationId={applicationId}
                currentSenderType="customer"
                getToken={getToken}
                initialMessages={reportMessages ?? []}
                onSend={async (body, file) => {
                  const { message } = await sendReportMessageWithAttachment(applicationId, body, file);
                  return message;
                }}
              />
            </div>
          </div>
        )}

        {/* ── CASE 2: CANCELLED ── */}
        {isCancelled && (
          <div className="p-4 bg-red-50 border border-red-200 rounded-xl text-xs text-red-900 flex items-center justify-between gap-4">
            <div className="flex items-center gap-2.5">
              <MessageCircle className="size-4 text-red-600 shrink-0" />
              <span>Demande annulée. La discussion avec le personnel reste accessible.</span>
            </div>
            <Link
              href={`/applications/${applicationId}/report`}
              className="btn-outline text-xs inline-flex items-center gap-1.5 shrink-0"
            >
              <span>Ouvrir la discussion</span>
            </Link>
          </div>
        )}

        {/* ── CASE 3: APPOINTMENT CARD (WHEN NOT LOCKED) ── */}
        {!isLocked && appointment ? (
          <div className="figma-card p-6 sm:p-8 bg-white space-y-6 shadow-xs">
            <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-4">
              <div className="flex items-center gap-2.5">
                <div className="size-8 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                  <Calendar className="size-4" />
                </div>
                <div>
                  <h2 className="text-base font-semibold text-[#0C1825]">
                    {isConfirmed ? 'Rendez-vous confirmé' : 'Demande acceptée — rendez-vous planifié'}
                  </h2>
                  <p className="text-xs text-[#3D5166]">Entretien technique et remise des pièces originales</p>
                </div>
              </div>

              <div className="flex items-center gap-2">
                <span className={`badge ${isConfirmed ? 'badge-success' : 'badge-warning'} text-xs`}>
                  {isConfirmed ? 'Rendez-vous confirmé' : remainingLabel}
                </span>
                {!isConfirmed && !isAtRescheduleLimit && (
                  <span className="text-[11px] text-[#3D5166] font-medium hidden sm:inline">
                    ({rescheduleCount}/{maxReschedules} utilisés)
                  </span>
                )}
              </div>
            </div>

            {/* Appointment Details Grid */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="p-4 rounded-xl border border-[#E0E4E9] bg-[#F4F6F8] space-y-1">
                <span className="text-[10px] uppercase font-bold text-[#3D5166] flex items-center gap-1">
                  <Calendar className="size-3.5 text-[#C0272D]" /> Date de l&apos;entretien
                </span>
                <p className="text-sm font-bold text-[#0C1825] capitalize">
                  {formatDate(appointment.scheduled_date)}
                </p>
              </div>

              <div className="p-4 rounded-xl border border-[#E0E4E9] bg-[#F4F6F8] space-y-1">
                <span className="text-[10px] uppercase font-bold text-[#3D5166] flex items-center gap-1">
                  <Clock className="size-3.5 text-[#C0272D]" /> Heure
                </span>
                <p className="text-sm font-bold text-[#0C1825] font-mono">
                  {formatTime(appointment.scheduled_time)}
                </p>
              </div>
            </div>

            {/* Branch info */}
            {appointment.branch && (
              <div className="p-4 rounded-xl border border-[#E0E4E9] bg-white space-y-2">
                <div className="flex items-center gap-2 text-xs font-bold text-[#0C1825]">
                  <Building2 className="size-4 text-[#C0272D]" />
                  <span>Agence BTS : {appointment.branch.name}</span>
                </div>
                <p className="text-xs text-[#3D5166] flex items-center gap-1.5 pl-6">
                  <MapPin className="size-3.5 text-[#C0272D] shrink-0" />
                  {[appointment.branch.ville, appointment.branch.address].filter(Boolean).join(' — ')}
                </p>
              </div>
            )}

            {isAtRescheduleLimit && !isConfirmed && (
              <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-xs text-amber-950" role="status">
                <p className="font-bold">Vous avez utilisé vos 4 changements de rendez-vous.</p>
                <p className="mt-1">Contactez votre agence pour toute nouvelle modification. Le créneau affiché reste valide et peut encore être confirmé.</p>
              </div>
            )}

            {/* Confirmed checklist and embedded chat */}
            {isConfirmed && (
              <div className="space-y-6">
                <div className="p-5 bg-emerald-50 border border-emerald-200 rounded-xl space-y-3 text-xs text-emerald-950">
                  <div className="flex items-center gap-2 font-bold text-emerald-900">
                    <CheckCircle2 className="size-4 text-emerald-600" />
                    <span>Votre entretien en agence est validé et verrouillé !</span>
                  </div>
                  <p>
                    Veuillez vous présenter à l&apos;agence <strong>{appointment.branch?.name ?? 'BTS'}</strong> muni des pièces justificatives originales :
                  </p>
                  <ul className="list-disc list-inside space-y-1 text-emerald-900 pl-2">
                    <li>Original de votre Carte d&apos;Identité Nationale (CIN)</li>
                    <li>Factures proforma / devis originaux</li>
                    <li>Justificatif de domicile récent</li>
                  </ul>
                </div>

              </div>
            )}

            {/* Actions for proposed appointments */}
            {!isConfirmed && !isCancelled && (
              <div className="space-y-2 pt-4 border-t border-[#E0E4E9]">
                <div className="flex flex-col sm:flex-row gap-3">
                  <button
                    type="button"
                    onClick={handleAccept}
                    disabled={working}
                    className="btn-red text-xs flex-1 shadow-xs"
                    style={{ padding: '10px 24px' }}
                  >
                    <CalendarCheck2 className="size-4" />
                    <span>{working ? 'Confirmation…' : 'Confirmer ce rendez-vous (Fixe & Définitif)'}</span>
                  </button>

                  <button
                    type="button"
                    onClick={() => setRejectOpen(true)}
                    disabled={working || !appointment.can_self_reschedule}
                    className="btn-outline text-xs"
                  >
                    {isAtRescheduleLimit ? '0 changement restant' : `Modifier le rendez-vous — ${remainingLabel}`}
                  </button>
                </div>
                <p className="text-[11px] text-[#3D5166]">
                  * Une fois confirmé, le créneau ne pourra plus être modifié en ligne.
                </p>
              </div>
            )}

            {isAtRescheduleLimit && !isConfirmed && (
              <div className="space-y-4 rounded-xl border border-[#E0E4E9] bg-[#F4F6F8] p-5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <h3 className="text-sm font-bold text-[#0C1825]">Discussion avec mon conseiller</h3>
                    <p className="mt-1 text-xs text-[#3D5166]">Cette discussion est sécurisée et transmise uniquement à votre agence responsable.</p>
                  </div>
                  <Link
                    href={`/applications/${applicationId}/report`}
                    className="btn-outline inline-flex items-center justify-center gap-2 text-xs focus:outline-none focus:ring-2 focus:ring-[#C0272D] focus:ring-offset-2"
                  >
                    <MessageCircle className="size-4" aria-hidden="true" />
                    Contacter mon agence
                  </Link>
                </div>
                <ReportChat
                  applicationId={applicationId}
                  currentSenderType="customer"
                  getToken={getToken}
                  initialMessages={reportMessages ?? []}
                  onSend={async (body, file) => {
                    const { message } = await sendReportMessageWithAttachment(applicationId, body, file);
                    return message;
                  }}
                />
              </div>
            )}
          </div>
        ) : !isLocked ? (
          <div className="figma-card p-10 bg-white text-center space-y-3">
            <Calendar className="size-8 text-gray-400 mx-auto" />
            <p className="text-sm font-semibold text-[#0C1825]">Aucun rendez-vous assigné pour le moment</p>
            <p className="text-xs text-[#3D5166] max-w-sm mx-auto">
              Un créneau en agence vous sera proposé dès que l&apos;instruction technique de votre dossier sera terminée.
            </p>
          </div>
        ) : null}

        {/* Decline / Change Dialog */}
        <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle className="font-display text-xl text-[#0C1825]">
                Changer de créneau horaire ?
              </DialogTitle>
              <DialogDescription className="text-xs text-[#3D5166] pt-2 leading-relaxed">
                Si vous confirmez, un nouveau créneau disponible vous sera immédiatement attribué. Cette opération utilisera le changement n° <strong>{rescheduleCount + 1} sur {maxReschedules}</strong>.
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
                disabled={working}
                className="btn-red text-xs"
              >
                {working ? 'Changement en cours…' : 'Valider le changement de créneau'}
              </button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </main>
    </div>
  );
}
