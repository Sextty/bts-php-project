'use client';

import { useEffect, useState, useRef, useMemo, type FormEvent } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  ArrowLeft,
  Send,
  Loader2,
  MessageSquareText,
  User,
  Shield,
  Calendar,
  Building2,
  Clock,
  CheckCircle2,
  FileText,
  Bell,
  Sparkles,
  Command,
  ChevronRight,
  ShieldAlert,
  Paperclip,
  X,
  Lock,
  LockOpen,
  UserX,
  Download,
} from 'lucide-react';
import { ErrorAlert } from '@/components/error-alert';
import { PageLoading } from '@/components/page-loading';
import {
  getReportMessages,
  sendReportMessageWithAttachment,
  listBranches,
  scheduleStaffAppointment,
  closeStaffReport,
  reopenStaffReport,
  banClientFromApplication,
  type ReportMessageDto,
  type BranchDto,
} from '@/lib/api/reports';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { ApiError, getApiBaseUrl } from '@/lib/api/client';
import { getStaffToken } from '@/lib/auth/staff-token';
import { createEchoClient } from '@/lib/echo';
import type Echo from 'laravel-echo';
import { cn } from '@/lib/utils';

interface SlashCommand {
  id: string;
  command: string;
  label: string;
  description: string;
  badge: string;
  icon: typeof Calendar;
  actionType: 'modal_schedule' | 'modal_branch' | 'insert_template' | 'modal_ban' | 'modal_close' | 'action_reopen';
  templateText?: string;
}

const SLASH_COMMANDS: SlashCommand[] = [
  {
    id: 'schedule_appointment',
    command: '/rdv',
    label: 'Fixer / Modifier le Rendez-vous & l\'Agence',
    description: 'Sélectionner l\'agence BTS, la date et l\'horaire pour le client.',
    badge: 'Action Rapide',
    icon: Calendar,
    actionType: 'modal_schedule',
  },
  {
    id: 'change_branch',
    command: '/agence',
    label: 'Changer l\'agence BTS de rattachement',
    description: 'Transférer le dossier et le rendez-vous vers une autre agence régionale.',
    badge: 'Agence',
    icon: Building2,
    actionType: 'modal_branch',
  },
  {
    id: 'confirm_appointment',
    command: '/confirmer-rdv',
    label: 'Confirmer définitivement le RDV',
    description: 'Fixer et verrouiller le créneau de rendez-vous.',
    badge: 'Validation',
    icon: CheckCircle2,
    actionType: 'modal_schedule',
  },
  {
    id: 'close_thread',
    command: '/cloturer-discussion',
    label: 'Clôturer le fil de discussion',
    description: 'Verrouiller les échanges pour ce dossier.',
    badge: 'Clôture',
    icon: Lock,
    actionType: 'modal_close',
  },
  {
    id: 'reopen_thread',
    command: '/rouvrir-discussion',
    label: 'Rouvrir le fil de discussion',
    description: 'Permettre à nouveau les échanges sur ce dossier.',
    badge: 'Réouverture',
    icon: LockOpen,
    actionType: 'action_reopen',
  },
  {
    id: 'ban_client',
    command: '/bannir',
    label: 'Bannir et suspendre le client',
    description: 'Suspendre immédiatement le compte du demandeur et clôturer le dossier.',
    badge: 'Sécurité',
    icon: UserX,
    actionType: 'modal_ban',
  },
  {
    id: 'request_docs',
    command: '/demande-pieces',
    label: 'Demander les justificatifs originaux',
    description: 'Insérer la liste des pièces requises (CIN, devis, justificatif de domicile).',
    badge: 'Modèle',
    icon: FileText,
    actionType: 'insert_template',
    templateText:
      "Bonjour,\n\nVeuillez vous présenter à votre agence BTS muni des pièces justificatives originales suivantes :\n1. Carte d'Identité Nationale (CIN) originale en cours de validité\n2. Factures proforma / devis originaux signés et tamponnés\n3. Justificatif de domicile récent (moins de 3 mois)\n\nRestant à votre entière disposition.",
  },
  {
    id: 'ask_availability',
    command: '/disponibilites',
    label: 'Demander les disponibilités du client',
    description: 'Proposer au client d\'indiquer ses créneaux de disponibilité souhaités.',
    badge: 'Modèle',
    icon: Clock,
    actionType: 'insert_template',
    templateText:
      "Bonjour,\n\nAfin de planifier votre entretien en agence dans les meilleures conditions, pourriez-vous nous préciser vos jours et horaires de disponibilité privilégiés ?",
  },
  {
    id: 'remind_client',
    command: '/relance',
    label: 'Rappel / Relance de présence',
    description: 'Insérer un rappel pour l\'entretien technique fixé.',
    badge: 'Modèle',
    icon: Bell,
    actionType: 'insert_template',
    templateText:
      "Bonjour,\n\nNous vous rappelons votre rendez-vous prévu à votre agence BTS. Merci de vous présenter à l'heure convenue avec votre dossier de pièces originales.\n\nCordialement,\nL'équipe BTS Bank.",
  },
];

const PRESET_HOURS = ['08:30', '09:00', '10:00', '11:00', '14:00', '15:00', '16:00'];

export default function ReportChatPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [messages, setMessages] = useState<ReportMessageDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(false);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [isClosed, setIsClosed] = useState(false);
  const [closedReason, setClosedReason] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [body, setBody] = useState('');
  const scrollRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // Slash commands navigation
  const [selectedIndex, setSelectedIndex] = useState(0);

  // Appointment & Branch modal state
  const [scheduleModalOpen, setScheduleModalOpen] = useState(false);
  const [branches, setBranches] = useState<BranchDto[]>([]);
  const [selectedBranchId, setSelectedBranchId] = useState<number | null>(null);
  const [scheduledDate, setScheduledDate] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    return d.toISOString().split('T')[0];
  });
  const [scheduledTime, setScheduledTime] = useState('09:00');
  const [directConfirm, setDirectConfirm] = useState(true);
  const [appointmentNotes, setAppointmentNotes] = useState('');
  const [scheduling, setScheduling] = useState(false);
  const [scheduleError, setScheduleError] = useState<string | null>(null);
  const [scheduleSuccess, setScheduleSuccess] = useState<string | null>(null);

  // Ban Client Modal state
  const [banModalOpen, setBanModalOpen] = useState(false);
  const [banReason, setBanReason] = useState('');
  const [banning, setBanning] = useState(false);
  const [banError, setBanError] = useState<string | null>(null);
  const [banSuccess, setBanSuccess] = useState<string | null>(null);

  // Close Thread Modal state
  const [closeModalOpen, setCloseModalOpen] = useState(false);
  const [closeReasonInput, setCloseReasonInput] = useState('');
  const [closing, setClosing] = useState(false);

  useEffect(() => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    if (!Number.isFinite(applicationId)) {
      router.replace('/reports');
      return;
    }
    getReportMessages(applicationId)
      .then((res: { messages: ReportMessageDto[]; is_closed?: boolean; closed_reason?: string | null } | ReportMessageDto[]) => {
        if (Array.isArray(res)) {
          setMessages(res);
        } else if (res && Array.isArray(res.messages)) {
          setMessages(res.messages);
          setIsClosed(!!res.is_closed);
          setClosedReason(res.closed_reason ?? null);
        } else {
          setMessages([]);
        }
      })
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : 'Erreur de chargement.');
        setMessages([]);
      })
      .finally(() => setLoading(false));
  }, [applicationId, router]);

  useEffect(() => {
    if (!Number.isFinite(applicationId)) return;
    const echo = createEchoClient(getStaffToken) as Echo<'reverb'>;
    const channel = echo.private(`application.${applicationId}.report`);

    channel.listen('.report.message', (event: ReportMessageDto) => {
      setMessages((prev) => (prev.some((m) => m.id === event.id) ? prev : [...prev, event]));
      if (event.body.startsWith('🔒')) {
        setIsClosed(true);
      } else if (event.body.startsWith('🔓')) {
        setIsClosed(false);
      }
    });

    const onError = (err: unknown) => {
      console.error('[admin-report-chat] websocket connection error', err);
    };
    echo.connector.pusher.connection.bind('error', onError);

    return () => {
      try {
        echo.connector?.pusher?.connection?.unbind('error', onError);
      } catch {}
      echo.leave(`application.${applicationId}.report`);
      echo.disconnect();
    };
  }, [applicationId]);

  useEffect(() => {
    listBranches()
      .then((res) => {
        if (res.branches && res.branches.length > 0) {
          setBranches(res.branches);
          setSelectedBranchId((prev) => prev || res.branches[0].id);
        }
      })
      .catch(() => {});
  }, []);

  useEffect(() => {
    if (scrollRef.current) {
      scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
    }
  }, [messages]);

  const isSlashActive = body.startsWith('/');
  const filteredCommands = useMemo(() => {
    if (!isSlashActive) return [];
    const query = body.toLowerCase().trim();
    return SLASH_COMMANDS.filter(
      (c) =>
        c.command.toLowerCase().startsWith(query) ||
        c.label.toLowerCase().includes(query.replace('/', '')) ||
        c.description.toLowerCase().includes(query.replace('/', ''))
    );
  }, [body, isSlashActive]);

  useEffect(() => {
    setSelectedIndex(0);
  }, [filteredCommands.length]);

  function executeSlashCommand(command: SlashCommand) {
    if (command.actionType === 'modal_schedule' || command.actionType === 'modal_branch') {
      setBody('');
      setScheduleError(null);
      setScheduleSuccess(null);
      setScheduleModalOpen(true);
      return;
    }

    if (command.actionType === 'modal_ban') {
      setBody('');
      setBanError(null);
      setBanSuccess(null);
      setBanModalOpen(true);
      return;
    }

    if (command.actionType === 'modal_close') {
      setBody('');
      setCloseModalOpen(true);
      return;
    }

    if (command.actionType === 'action_reopen') {
      setBody('');
      handleReopenThread();
      return;
    }

    if (command.actionType === 'insert_template' && command.templateText) {
      setBody(command.templateText);
      inputRef.current?.focus();
    }
  }

  async function handleSend(e: FormEvent) {
    e.preventDefault();
    const trimmed = body.trim();
    if ((!trimmed && !selectedFile) || sending || isClosed) return;

    const exactCmd = SLASH_COMMANDS.find((c) => c.command.toLowerCase() === trimmed.toLowerCase());
    if (exactCmd) {
      executeSlashCommand(exactCmd);
      return;
    }

    setSending(true);
    setError(null);
    try {
      const { message } = await sendReportMessageWithAttachment(applicationId, trimmed, selectedFile);
      setMessages((prev) => [...prev, message]);
      setBody('');
      setSelectedFile(null);
      if (fileInputRef.current) fileInputRef.current.value = '';
    } catch (err: unknown) {
      if (err instanceof ApiError) {
        const fieldError = err.fields ? Object.values(err.fields).flat()[0] : null;
        setError(fieldError || err.message || 'Erreur lors de l\'envoi du message.');
      } else if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Erreur lors de l'envoi du message.");
      }
    } finally {
      setSending(false);
    }
  }

  async function handleBanSubmit() {
    if (!banReason.trim()) {
      setBanError('Veuillez spécifier le motif du bannissement.');
      return;
    }

    setBanning(true);
    setBanError(null);
    try {
      const res = await banClientFromApplication(applicationId, banReason.trim());
      if (res.message) {
        setMessages((prev) => (prev.some((m) => m.id === res.message.id) ? prev : [...prev, res.message]));
      }
      setIsClosed(true);
      setClosedReason(`Compte suspendu : ${banReason.trim()}`);
      setBanSuccess('Le client a été banni avec succès et ses accès ont été révoqués.');
      setTimeout(() => {
        setBanModalOpen(false);
        setBanSuccess(null);
      }, 1500);
    } catch (err: unknown) {
      setBanError(err instanceof Error ? err.message : 'Impossible de bannir le client.');
    } finally {
      setBanning(false);
    }
  }

  async function handleCloseThreadSubmit() {
    setClosing(true);
    try {
      const res = await closeStaffReport(applicationId, closeReasonInput.trim() || undefined);
      if (res.message) {
        setMessages((prev) => (prev.some((m) => m.id === res.message.id) ? prev : [...prev, res.message]));
      }
      setIsClosed(true);
      setClosedReason(closeReasonInput.trim() || 'Échanges terminés');
      setCloseModalOpen(false);
    } catch {
      setError('Impossible de clôturer la discussion.');
    } finally {
      setClosing(false);
    }
  }

  async function handleReopenThread() {
    try {
      const res = await reopenStaffReport(applicationId);
      if (res.message) {
        setMessages((prev) => (prev.some((m) => m.id === res.message.id) ? prev : [...prev, res.message]));
      }
      setIsClosed(false);
      setClosedReason(null);
    } catch {
      setError('Impossible de rouvrir la discussion.');
    }
  }

  function handleFileSelect(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;

    if (file.size > 10 * 1024 * 1024) {
      setError('Le fichier sélectionné dépasse la limite autorisée de 10 Mo.');
      return;
    }
    setError(null);
    setSelectedFile(file);
  }

  async function handleScheduleSubmit() {
    if (!scheduledDate || !scheduledTime) {
      setScheduleError('Veuillez sélectionner une date et une heure valides.');
      return;
    }

    setScheduling(true);
    setScheduleError(null);
    try {
      const res = await scheduleStaffAppointment(applicationId, {
        branch_id: selectedBranchId,
        scheduled_date: scheduledDate,
        scheduled_time: scheduledTime,
        direct_confirm: directConfirm,
        message_body: appointmentNotes.trim() || undefined,
      });

      if (res.message) {
        setMessages((prev) => (prev.some((m) => m.id === res.message.id) ? prev : [...prev, res.message]));
      }

      setScheduleSuccess('Rendez-vous et agence mis à jour avec succès !');
      setTimeout(() => {
        setScheduleModalOpen(false);
        setScheduleSuccess(null);
      }, 1200);
    } catch (err: unknown) {
      setScheduleError(err instanceof Error ? err.message : 'Impossible de planifier le rendez-vous.');
    } finally {
      setScheduling(false);
    }
  }

  if (loading) return <PageLoading />;

  return (
    <div className="px-4 sm:px-8 py-8 max-w-4xl mx-auto space-y-4">
      {/* ── Header ── */}
      <div className="flex items-center justify-between">
        <div>
          <Link href="/reports" className="inline-flex items-center gap-1.5 text-sm font-medium text-[#3D5166] hover:text-[#C0272D] transition-colors mb-2">
            <ArrowLeft className="size-4" />
            Retour aux discussions
          </Link>
          <h1 className="text-xl font-bold text-[#0C1825] flex items-center gap-2">
            <MessageSquareText className="size-5 text-[#C0272D]" />
            Discussion — Dossier #{applicationId}
          </h1>
        </div>
      </div>

      <ErrorAlert message={error} />

      {/* ── Chat Container ── */}
      <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs overflow-hidden flex flex-col" style={{ height: 'calc(100vh - 240px)', minHeight: '460px' }}>
        {/* Messages list */}
        <div ref={scrollRef} className="flex-1 overflow-y-auto px-6 py-5 space-y-4 bg-[#FAFBFD]">
          {(messages || []).length === 0 ? (
            <div className="flex flex-col items-center gap-3 py-16 text-center">
              <MessageSquareText className="size-10 text-[#E0E4E9]" />
              <p className="text-sm font-semibold text-[#0C1825]">Aucun message pour le moment.</p>
              <p className="text-xs text-[#3D5166]">Utilisez les commandes pour fixer un rendez-vous ou envoyer un message au demandeur.</p>
            </div>
          ) : (
            (messages || []).map((msg) => {
              const isStaff = msg.sender_type === 'staff';
              const isSystemAnnouncement = msg.body.startsWith('📅') || msg.body.startsWith('🔒') || msg.body.startsWith('🚫') || msg.body.startsWith('🔓');

              const downloadUrl = msg.attachment_url
                ? `${getApiBaseUrl()}${msg.attachment_url}`
                : `${getApiBaseUrl()}/api/staff/reports/${applicationId}/messages/${msg.id}/attachment`;

              return (
                <div key={msg.id} className={`flex ${isStaff ? 'justify-end' : 'justify-start'}`}>
                  <div className={`max-w-[80%] ${isStaff ? 'order-2' : ''}`}>
                    <div className={`flex items-center gap-1.5 mb-1 ${isStaff ? 'justify-end' : ''}`}>
                      {isStaff ? (
                        <Shield className="size-3 text-[#C0272D]" />
                      ) : (
                        <User className="size-3 text-blue-600" />
                      )}
                      <span className="text-[10px] font-bold text-[#3D5166]">{msg.sender_name}</span>
                      <span className="text-[10px] text-[#3D5166]/50">
                        {new Date(msg.created_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}
                      </span>
                    </div>
                    <div className={cn(
                      'rounded-2xl px-4 py-3 text-xs leading-relaxed whitespace-pre-wrap shadow-2xs',
                      isSystemAnnouncement
                        ? 'bg-amber-50 text-amber-950 border border-amber-200'
                        : isStaff
                          ? 'bg-[#C0272D] text-white rounded-tr-xs'
                          : 'bg-white text-[#0C1825] rounded-tl-xs border border-[#E0E4E9]'
                    )}>
                      {msg.body}

                      {/* Attachment card */}
                      {msg.has_attachment && (
                        <div className="mt-2 pt-2 border-t border-current/20">
                          <a
                            href={downloadUrl}
                            target="_blank"
                            rel="noreferrer"
                            className={cn(
                              'inline-flex items-center gap-2 p-2 rounded-xl text-xs font-medium transition-colors',
                              isStaff
                                ? 'bg-white/10 hover:bg-white/20 text-white'
                                : 'bg-[#F4F6F8] hover:bg-[#E0E4E9] text-[#0C1825]'
                            )}
                          >
                            <FileText className="size-4 shrink-0" />
                            <span className="truncate max-w-[200px]">{msg.attachment_name || 'Pièce jointe'}</span>
                            <Download className="size-3.5 shrink-0 opacity-70" />
                          </a>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              );
            })
          )}
        </div>

        {/* ── Closed Thread Banner (if closed) ── */}
        {isClosed && (
          <div className="px-4 py-2.5 bg-gray-100 border-t border-[#E0E4E9] flex items-center justify-between gap-3 text-xs text-gray-800">
            <div className="flex items-center gap-2">
              <Lock className="size-4 text-gray-500 shrink-0" />
              <span>
                <strong>Discussion clôturée</strong>
                {closedReason ? ` — Motif : ${closedReason}` : ''}
              </span>
            </div>
            <button
              type="button"
              onClick={handleReopenThread}
              className="btn-outline text-[11px] px-2.5 py-1 inline-flex items-center gap-1 shrink-0 bg-white"
            >
              <LockOpen className="size-3 text-emerald-600" />
              <span>Rouvrir discussion</span>
            </button>
          </div>
        )}

        {/* ── Quick Action Presets Toolbar ── */}
        <div className="flex items-center gap-1.5 px-4 py-2 bg-[#F4F6F8] border-t border-[#E0E4E9] overflow-x-auto text-xs">
          <span className="text-[10px] font-bold uppercase text-[#3D5166] flex items-center gap-1 shrink-0">
            <Sparkles className="size-3 text-[#C0272D]" /> Actions :
          </span>

          <button
            type="button"
            onClick={() => {
              setScheduleError(null);
              setScheduleSuccess(null);
              setScheduleModalOpen(true);
            }}
            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white border border-[#E0E4E9] text-[11px] font-semibold text-[#0C1825] hover:border-[#C0272D] hover:text-[#C0272D] transition-colors shrink-0 shadow-2xs"
          >
            <Calendar className="size-3 text-[#C0272D]" />
            <span>Fixer / Modifier RDV</span>
          </button>

          <button
            type="button"
            onClick={() => {
              setScheduleError(null);
              setScheduleSuccess(null);
              setScheduleModalOpen(true);
            }}
            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white border border-[#E0E4E9] text-[11px] font-semibold text-[#0C1825] hover:border-[#C0272D] hover:text-[#C0272D] transition-colors shrink-0 shadow-2xs"
          >
            <Building2 className="size-3 text-[#C0272D]" />
            <span>Changer Agence</span>
          </button>

          {isClosed ? (
            <button
              type="button"
              onClick={handleReopenThread}
              className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-50 border border-emerald-300 text-[11px] font-semibold text-emerald-800 hover:bg-emerald-100 transition-colors shrink-0 shadow-2xs"
            >
              <LockOpen className="size-3 text-emerald-600" />
              <span>Rouvrir discussion</span>
            </button>
          ) : (
            <button
              type="button"
              onClick={() => setCloseModalOpen(true)}
              className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white border border-[#E0E4E9] text-[11px] font-semibold text-gray-700 hover:border-gray-400 transition-colors shrink-0 shadow-2xs"
            >
              <Lock className="size-3 text-gray-500" />
              <span>Clôturer discussion</span>
            </button>
          )}

          <button
            type="button"
            onClick={() => {
              setBanError(null);
              setBanSuccess(null);
              setBanModalOpen(true);
            }}
            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-red-50 border border-red-200 text-[11px] font-semibold text-red-700 hover:bg-red-100 transition-colors shrink-0 shadow-2xs"
          >
            <UserX className="size-3 text-red-600" />
            <span>Bannir demandeur</span>
          </button>

          <button
            type="button"
            onClick={() => {
              setBody('/demande-pieces');
              const cmd = SLASH_COMMANDS.find((c) => c.id === 'request_docs');
              if (cmd) executeSlashCommand(cmd);
            }}
            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white border border-[#E0E4E9] text-[11px] font-medium text-[#3D5166] hover:text-[#0C1825] transition-colors shrink-0"
          >
            <FileText className="size-3" />
            <span>Demande pièces</span>
          </button>

          <button
            type="button"
            onClick={() => {
              setBody('/relance');
              const cmd = SLASH_COMMANDS.find((c) => c.id === 'remind_client');
              if (cmd) executeSlashCommand(cmd);
            }}
            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white border border-[#E0E4E9] text-[11px] font-medium text-[#3D5166] hover:text-[#0C1825] transition-colors shrink-0"
          >
            <Bell className="size-3" />
            <span>Relance</span>
          </button>

          <button
            type="button"
            onClick={() => {
              setBody('/');
              inputRef.current?.focus();
            }}
            className="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-[#C0272D]/10 text-[#C0272D] font-mono text-[10px] font-bold hover:bg-[#C0272D]/20 transition-colors shrink-0 ml-auto"
            title="Tapez / pour ouvrir le menu des commandes"
          >
            <span>Tapez &quot;/&quot; pour voir tout</span>
          </button>
        </div>

        {/* ── Slash Command Autocomplete Overlay & Input Form ── */}
        <div className="relative border-t border-[#E0E4E9] bg-[#F4F6F8]/50 p-4 space-y-2">
          {isSlashActive && filteredCommands.length > 0 && (
            <div className="absolute bottom-full left-4 right-4 mb-2 rounded-xl bg-white border border-[#E0E4E9] shadow-xl overflow-hidden z-50 divide-y divide-[#F4F6F8]">
              <div className="px-3 py-2 bg-[#F4F6F8] flex items-center justify-between">
                <span className="text-[11px] font-bold text-[#0C1825] flex items-center gap-1.5">
                  <Command className="size-3 text-[#C0272D]" /> Commandes disponibles ({filteredCommands.length})
                </span>
                <span className="text-[10px] text-[#3D5166]">Utilisez ↑ ↓ pour naviguer, Entrée pour valider</span>
              </div>
              <div className="max-h-56 overflow-y-auto">
                {filteredCommands.map((cmd, index) => {
                  const IconComponent = cmd.icon;
                  const isSelected = index === selectedIndex;

                  return (
                    <button
                      key={cmd.id}
                      type="button"
                      onClick={() => executeSlashCommand(cmd)}
                      onMouseEnter={() => setSelectedIndex(index)}
                      className={cn(
                        'w-full text-left px-3 py-2.5 flex items-center justify-between gap-3 transition-colors',
                        isSelected ? 'bg-[#FDF2F2] text-[#0C1825]' : 'hover:bg-gray-50'
                      )}
                    >
                      <div className="flex items-center gap-2.5 min-w-0">
                        <div className={cn(
                          'size-7 rounded-lg flex items-center justify-center shrink-0',
                          isSelected ? 'bg-[#C0272D] text-white' : 'bg-[#F4F6F8] text-[#3D5166]'
                        )}>
                          <IconComponent className="size-3.5" />
                        </div>
                        <div className="min-w-0">
                          <div className="flex items-center gap-2">
                            <span className="font-mono text-xs font-bold text-[#C0272D]">{cmd.command}</span>
                            <span className="text-xs font-semibold text-[#0C1825] truncate">{cmd.label}</span>
                          </div>
                          <p className="text-[11px] text-[#3D5166] truncate">{cmd.description}</p>
                        </div>
                      </div>
                      <div className="flex items-center gap-2 shrink-0">
                        <span className="badge text-[9px] bg-[#F4F6F8] text-[#3D5166] border border-[#E0E4E9]">
                          {cmd.badge}
                        </span>
                        <ChevronRight className={cn('size-3.5', isSelected ? 'text-[#C0272D]' : 'text-transparent')} />
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>
          )}

          {/* Selected File Pill */}
          {selectedFile && (
            <div className="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-red-50 border border-red-200 text-xs text-[#C0272D] font-medium animate-in fade-in duration-200">
              <FileText className="size-3.5" />
              <span className="truncate max-w-[240px]">{selectedFile.name}</span>
              <span className="text-[10px] text-gray-500">
                ({(selectedFile.size / 1024).toFixed(0)} Ko)
              </span>
              <button
                type="button"
                onClick={() => {
                  setSelectedFile(null);
                  if (fileInputRef.current) fileInputRef.current.value = '';
                }}
                className="hover:text-red-800 p-0.5"
                title="Retirer le fichier"
              >
                <X className="size-3.5" />
              </button>
            </div>
          )}

          <form onSubmit={handleSend}>
            <div className="flex items-center gap-2">
              <input
                ref={fileInputRef}
                type="file"
                onChange={handleFileSelect}
                accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx"
                className="hidden"
                id={`admin-file-attach-${applicationId}`}
              />

              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                className="btn-outline size-11 shrink-0 flex items-center justify-center rounded-xl hover:border-[#C0272D] hover:text-[#C0272D] transition-colors"
                title="Joindre un document (PDF, image, devis…)"
              >
                <Paperclip className="size-4" />
              </button>

              <input
                ref={inputRef}
                type="text"
                value={body}
                onChange={(e) => setBody(e.target.value)}
                onKeyDown={(e) => {
                  if (isSlashActive && filteredCommands.length > 0) {
                    if (e.key === 'ArrowDown') {
                      e.preventDefault();
                      setSelectedIndex((prev) => (prev + 1) % filteredCommands.length);
                      return;
                    }
                    if (e.key === 'ArrowUp') {
                      e.preventDefault();
                      setSelectedIndex((prev) => (prev - 1 + filteredCommands.length) % filteredCommands.length);
                      return;
                    }
                    if (e.key === 'Enter' || e.key === 'Tab') {
                      e.preventDefault();
                      executeSlashCommand(filteredCommands[selectedIndex]);
                      return;
                    }
                    if (e.key === 'Escape') {
                      e.preventDefault();
                      setBody((prev) => (prev.startsWith('/') ? '' : prev));
                      return;
                    }
                  }
                }}
                placeholder="Écrire un message ou taper '/' pour afficher les actions (ex: /rdv, /agence, /bannir)…"
                className="flex-1 text-xs bg-white rounded-xl border border-[#E0E4E9] px-4 py-3 focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]/20 placeholder:text-[#3D5166]/50"
                disabled={sending}
              />
              <button
                type="submit"
                disabled={sending || (!body.trim() && !selectedFile)}
                className="size-11 rounded-xl bg-[#C0272D] text-white flex items-center justify-center hover:bg-[#A52025] disabled:opacity-40 disabled:cursor-not-allowed transition-colors shadow-xs shrink-0"
                title="Envoyer"
              >
                {sending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
              </button>
            </div>
          </form>
        </div>
      </div>

      {/* ── Interactive Modal: Schedule & Change Agency ── */}
      <Dialog open={scheduleModalOpen} onOpenChange={setScheduleModalOpen}>
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle className="font-display text-xl text-[#0C1825] flex items-center gap-2">
              <Calendar className="size-5 text-[#C0272D]" />
              Fixer ou Réajuster le Rendez-vous en Agence
            </DialogTitle>
            <DialogDescription className="text-xs text-[#3D5166] pt-1 leading-relaxed">
              Sélectionnez l&apos;agence BTS, la date et le créneau horaire pour ce dossier. La modification sera immédiatement enregistrée et notifiée au client.
            </DialogDescription>
          </DialogHeader>

          {scheduleError && (
            <div className="p-3 bg-red-50 border border-red-200 rounded-xl text-xs text-red-700 flex items-center gap-2">
              <ShieldAlert className="size-4 shrink-0 text-red-600" />
              <span>{scheduleError}</span>
            </div>
          )}

          {scheduleSuccess && (
            <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl text-xs text-emerald-800 flex items-center gap-2 font-medium">
              <CheckCircle2 className="size-4 shrink-0 text-emerald-600" />
              <span>{scheduleSuccess}</span>
            </div>
          )}

          <div className="space-y-4 pt-2">
            {/* 1. Branch Selector (25 BTS Agencies) */}
            <div className="space-y-1.5">
              <label htmlFor="admin_branch_select" className="text-xs font-bold text-[#0C1825] flex items-center gap-1.5">
                <Building2 className="size-3.5 text-[#C0272D]" />
                Agence Régionale BTS ({branches.length} agences disponibles)
              </label>
              <select
                id="admin_branch_select"
                value={selectedBranchId ?? ''}
                onChange={(e) => setSelectedBranchId(Number(e.target.value))}
                className="w-full rounded-xl border border-[#E0E4E9] bg-white px-3 py-2.5 text-xs text-[#0C1825] focus:border-[#C0272D] focus:outline-none"
              >
                {branches.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name} — {b.ville} ({b.address})
                  </option>
                ))}
              </select>
            </div>

            {/* 2. Date Picker */}
            <div className="space-y-1.5">
              <label htmlFor="admin_schedule_date" className="text-xs font-bold text-[#0C1825] flex items-center gap-1.5">
                <Calendar className="size-3.5 text-[#C0272D]" />
                Date du Rendez-vous
              </label>
              <input
                id="admin_schedule_date"
                type="date"
                value={scheduledDate}
                min={new Date().toISOString().split('T')[0]}
                onChange={(e) => setScheduledDate(e.target.value)}
                className="w-full rounded-xl border border-[#E0E4E9] bg-white px-3 py-2.5 text-xs text-[#0C1825] focus:border-[#C0272D] focus:outline-none"
              />
            </div>

            {/* 3. Time Picker & Quick Presets */}
            <div className="space-y-2">
              <label htmlFor="admin_schedule_time" className="text-xs font-bold text-[#0C1825] flex items-center gap-1.5">
                <Clock className="size-3.5 text-[#C0272D]" />
                Créneau Horaire
              </label>
              <div className="flex flex-wrap gap-1.5">
                {PRESET_HOURS.map((hour) => (
                  <button
                    key={hour}
                    type="button"
                    onClick={() => setScheduledTime(hour)}
                    className={cn(
                      'px-3 py-1.5 rounded-lg text-xs font-semibold transition-all border',
                      scheduledTime === hour
                        ? 'bg-[#C0272D] text-white border-[#C0272D]'
                        : 'bg-[#F4F6F8] text-[#3D5166] border-[#E0E4E9] hover:bg-white'
                    )}
                  >
                    {hour}
                  </button>
                ))}
              </div>
              <input
                id="admin_schedule_time"
                type="time"
                value={scheduledTime}
                onChange={(e) => setScheduledTime(e.target.value)}
                className="w-full rounded-xl border border-[#E0E4E9] bg-white px-3 py-2 text-xs text-[#0C1825] focus:border-[#C0272D] focus:outline-none font-mono"
              />
            </div>

            {/* 4. Action Mode: Direct Confirm vs Propose */}
            <div className="space-y-1.5 pt-1">
              <span className="text-xs font-bold text-[#0C1825]">Type de validation</span>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <label className={cn(
                  'flex items-start gap-2.5 p-3 rounded-xl border cursor-pointer transition-all',
                  directConfirm ? 'border-[#C0272D] bg-[#FDF2F2]/40' : 'border-[#E0E4E9] bg-white'
                )}>
                  <input
                    type="radio"
                    name="admin_direct_confirm"
                    checked={directConfirm}
                    onChange={() => setDirectConfirm(true)}
                    className="mt-0.5 text-[#C0272D] focus:ring-[#C0272D]"
                  />
                  <div>
                    <span className="text-xs font-bold text-[#0C1825] block">Confirmer & Verrouiller</span>
                    <span className="text-[10px] text-[#3D5166]">Le rendez-vous est immédiatement validé comme fixe et définitif.</span>
                  </div>
                </label>

                <label className={cn(
                  'flex items-start gap-2.5 p-3 rounded-xl border cursor-pointer transition-all',
                  !directConfirm ? 'border-[#C0272D] bg-[#FDF2F2]/40' : 'border-[#E0E4E9] bg-white'
                )}>
                  <input
                    type="radio"
                    name="admin_direct_confirm"
                    checked={!directConfirm}
                    onChange={() => setDirectConfirm(false)}
                    className="mt-0.5 text-[#C0272D] focus:ring-[#C0272D]"
                  />
                  <div>
                    <span className="text-xs font-bold text-[#0C1825] block">Proposer au client</span>
                    <span className="text-[10px] text-[#3D5166]">Le client reçoit la proposition et peut la confirmer sur son espace.</span>
                  </div>
                </label>
              </div>
            </div>

            {/* 5. Custom Note */}
            <div className="space-y-1.5">
              <label htmlFor="admin_appointment_notes" className="text-xs font-bold text-[#0C1825]">
                Note / Instructions pour le client (optionnel)
              </label>
              <textarea
                id="admin_appointment_notes"
                value={appointmentNotes}
                onChange={(e) => setAppointmentNotes(e.target.value)}
                placeholder="Ex : Veuillez vous présenter au guichet n°2 avec vos factures proforma originales…"
                className="w-full rounded-xl border border-[#E0E4E9] bg-white px-3 py-2 text-xs text-[#0C1825] focus:border-[#C0272D] focus:outline-none min-h-16 resize-none"
              />
            </div>
          </div>

          <DialogFooter className="gap-2 pt-4 border-t border-[#E0E4E9]">
            <button
              type="button"
              onClick={() => setScheduleModalOpen(false)}
              className="btn-outline text-xs"
              disabled={scheduling}
            >
              Annuler
            </button>
            <button
              type="button"
              onClick={handleScheduleSubmit}
              disabled={scheduling}
              className="btn-red text-xs inline-flex items-center gap-1.5"
            >
              {scheduling ? (
                <>
                  <Loader2 className="size-3.5 animate-spin" />
                  <span>Enregistrement…</span>
                </>
              ) : (
                <>
                  <CheckCircle2 className="size-3.5" />
                  <span>Valider et Notifier le Client</span>
                </>
              )}
            </button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Ban Client Modal ── */}
      <Dialog open={banModalOpen} onOpenChange={setBanModalOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="font-display text-xl text-red-600 flex items-center gap-2">
              <UserX className="size-5" />
              Bannir et Suspendre le Demandeur
            </DialogTitle>
            <DialogDescription className="text-xs text-[#3D5166] pt-1">
              Cette action suspendra immédiatement le compte du client, révoquera ses sessions et clôturera définitivement cette discussion.
            </DialogDescription>
          </DialogHeader>

          {banError && (
            <div className="p-3 bg-red-50 border border-red-200 rounded-xl text-xs text-red-700 flex items-center gap-2">
              <ShieldAlert className="size-4 shrink-0 text-red-600" />
              <span>{banError}</span>
            </div>
          )}

          {banSuccess && (
            <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl text-xs text-emerald-800 flex items-center gap-2 font-medium">
              <CheckCircle2 className="size-4 shrink-0 text-emerald-600" />
              <span>{banSuccess}</span>
            </div>
          )}

          <div className="space-y-3 pt-2">
            <div className="space-y-1.5">
              <label htmlFor="admin_ban_reason_input" className="text-xs font-bold text-[#0C1825]">
                Motif précis du bannissement <span className="text-red-500">*</span>
              </label>
              <textarea
                id="admin_ban_reason_input"
                value={banReason}
                onChange={(e) => setBanReason(e.target.value)}
                placeholder="Ex : Fraude constatée sur les justificatifs fournis, comportement inapproprié…"
                className="w-full rounded-xl border border-[#E0E4E9] bg-white px-3 py-2 text-xs text-[#0C1825] focus:border-red-500 focus:outline-none min-h-20 resize-none"
              />
            </div>
          </div>

          <DialogFooter className="gap-2 pt-3 border-t border-[#E0E4E9]">
            <button
              type="button"
              onClick={() => setBanModalOpen(false)}
              className="btn-outline text-xs"
              disabled={banning}
            >
              Annuler
            </button>
            <button
              type="button"
              onClick={handleBanSubmit}
              disabled={banning || !!banSuccess}
              className="px-4 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-xs font-semibold inline-flex items-center gap-1.5 transition-colors"
            >
              {banning ? (
                <>
                  <Loader2 className="size-3.5 animate-spin" />
                  <span>Bannissement…</span>
                </>
              ) : (
                <>
                  <UserX className="size-3.5" />
                  <span>Confirmer le Bannissement</span>
                </>
              )}
            </button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Close Thread Modal ── */}
      <Dialog open={closeModalOpen} onOpenChange={setCloseModalOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="font-display text-xl text-[#0C1825] flex items-center gap-2">
              <Lock className="size-5 text-[#C0272D]" />
              Clôturer le Fil de Discussion
            </DialogTitle>
            <DialogDescription className="text-xs text-[#3D5166] pt-1">
              La discussion sera verrouillée pour le client. Vous pourrez la rouvrir à tout moment si nécessaire.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3 pt-2">
            <div className="space-y-1.5">
              <label htmlFor="admin_close_reason_input" className="text-xs font-bold text-[#0C1825]">
                Motif de clôture (optionnel)
              </label>
              <input
                id="admin_close_reason_input"
                type="text"
                value={closeReasonInput}
                onChange={(e) => setCloseReasonInput(e.target.value)}
                placeholder="Ex : Entretien planifié et pièces transmises"
                className="w-full rounded-xl border border-[#E0E4E9] bg-white px-3 py-2.5 text-xs text-[#0C1825] focus:border-[#C0272D] focus:outline-none"
              />
            </div>
          </div>

          <DialogFooter className="gap-2 pt-3 border-t border-[#E0E4E9]">
            <button
              type="button"
              onClick={() => setCloseModalOpen(false)}
              className="btn-outline text-xs"
              disabled={closing}
            >
              Annuler
            </button>
            <button
              type="button"
              onClick={handleCloseThreadSubmit}
              disabled={closing}
              className="btn-red text-xs inline-flex items-center gap-1.5"
            >
              {closing ? (
                <>
                  <Loader2 className="size-3.5 animate-spin" />
                  <span>Clôture…</span>
                </>
              ) : (
                <>
                  <Lock className="size-3.5" />
                  <span>Clôturer la discussion</span>
                </>
              )}
            </button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

