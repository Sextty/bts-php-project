'use client';

import { useEffect, useRef, useState } from 'react';
import type Echo from 'laravel-echo';
import {
  Send,
  Paperclip,
  FileText,
  Download,
  X,
  File,
  Lock,
  Loader2,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { getApiBaseUrl, ApiError } from '@/lib/api/client';
import type { ReportMessageDto } from '@/lib/api/reports';
import { createEchoClient } from '@/lib/echo';

const REPORT_ATTACHMENT_FORMATS =
  '.pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt,.ppt,.pptx';

interface ReportChatProps {
  applicationId: number;
  currentSenderType: 'customer' | 'staff';
  getToken: () => string | null;
  initialMessages: ReportMessageDto[];
  isClosed?: boolean;
  closedReason?: string | null;
  onSend: (body: string, file?: File | null) => Promise<ReportMessageDto>;
}

export function ReportChat({
  ...props
}: ReportChatProps) {
  const lastInitialMessageId = props.initialMessages.at(-1)?.id ?? 0;
  const sessionKey = `${props.applicationId}:${props.initialMessages.length}:${lastInitialMessageId}:${props.isClosed ? 1 : 0}`;

  return <ReportChatSession key={sessionKey} {...props} />;
}

function ReportChatSession({
  applicationId,
  currentSenderType,
  getToken,
  initialMessages,
  isClosed: propIsClosed,
  closedReason: propClosedReason,
  onSend,
}: ReportChatProps) {
  const [messages, setMessages] = useState<ReportMessageDto[]>(initialMessages);
  const [draft, setDraft] = useState('');
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [sending, setSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [isClosed, setIsClosed] = useState<boolean>(!!propIsClosed);
  const closedReason = propClosedReason ?? null;
  const [connectionState, setConnectionState] = useState<'connecting' | 'live' | 'offline'>('connecting');
  const bottomRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const echo = createEchoClient(getToken) as Echo<'reverb'>;
    const channel = echo.private(`application.${applicationId}.report`);

    channel.listen('.report.message', (event: ReportMessageDto) => {
      setMessages((prev) => (prev.some((m) => m.id === event.id) ? prev : [...prev, event]));
      if (event.body.startsWith('🔒')) {
        setIsClosed(true);
      } else if (event.body.startsWith('🔓')) {
        setIsClosed(false);
      }
    });
    channel.subscribed(() => setConnectionState('live'));
    channel.error((error: unknown) => {
      setConnectionState('offline');
      console.error('[report-chat] channel error', error);
    });
    const onError = (error: unknown) => {
      console.error('[report-chat] websocket connection error', error);
    };
    echo.connector.pusher.connection.bind('error', onError);

    return () => {
      try {
        echo.connector?.pusher?.connection?.unbind('error', onError);
      } catch {}
      echo.leave(`application.${applicationId}.report`);
      echo.disconnect();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [applicationId]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages.length]);

  async function handleSend() {
    const body = draft.trim();
    if ((!body && !selectedFile) || sending || isClosed) return;

    setSending(true);
    setSendError(null);
    try {
      const message = await onSend(body, selectedFile);
      setMessages((prev) => (prev.some((m) => m.id === message.id) ? prev : [...prev, message]));
      setDraft('');
      setSelectedFile(null);
      if (fileInputRef.current) fileInputRef.current.value = '';
    } catch (err: unknown) {
      if (err instanceof ApiError) {
        const fieldError = err.fields ? Object.values(err.fields).flat()[0] : null;
        setSendError(fieldError || err.message || 'Erreur lors de l\'envoi du message.');
      } else if (err instanceof Error) {
        setSendError(err.message);
      } else {
        setSendError('Votre message n\'a pas pu être envoyé. Veuillez réessayer.');
      }
    } finally {
      setSending(false);
    }
  }

  const lastMessage = messages[messages.length - 1];
  const isWaitingForStaffReply =
    !isClosed && currentSenderType === 'customer' && lastMessage?.sender_type === 'customer';

  function handleFileSelect(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;

    if (file.size > 10 * 1024 * 1024) {
      setSendError('Le fichier sélectionné dépasse la limite autorisée de 10 Mo.');
      return;
    }
    setSendError(null);
    setSelectedFile(file);
  }

  return (
    <div className="flex h-[32rem] flex-col rounded-xl border border-border/60 bg-card shadow-xs overflow-hidden">
      <div className="flex items-center justify-between gap-2 border-b border-border/60 px-4 py-3 bg-muted/20">
        <h2 className="text-xs font-bold text-[#0C1825] uppercase tracking-wider">Discussion & Signalement</h2>
        <span
          className="flex items-center gap-1.5 text-xs text-muted-foreground"
          role="status"
          aria-live="polite"
        >
          <span
            className={cn(
              'size-1.5 rounded-full',
              connectionState === 'live'
                ? 'bg-emerald-500'
                : connectionState === 'offline'
                  ? 'bg-destructive'
                  : 'bg-muted-foreground/40'
            )}
          />
          {connectionState === 'live' ? 'En direct' : connectionState === 'offline' ? 'Hors ligne' : 'Connexion…'}
        </span>
      </div>

      {/* ── Messages List ── */}
      <div
        role="log"
        aria-live="polite"
        aria-label="Messages"
        className="flex-1 space-y-3 overflow-y-auto px-4 py-4 bg-[#FAFBFD]"
      >
        {messages.length === 0 ? (
          <div className="py-12 text-center space-y-1">
            <p className="text-sm font-semibold text-foreground">Aucun message pour l&apos;instant</p>
            <p className="text-xs text-muted-foreground">
              Vous pouvez poser une question ou envoyer des pièces jointes à votre conseiller.
            </p>
          </div>
        ) : (
          messages.map((message) => {
            const isMine = message.sender_type === currentSenderType;
            const isSystemAnnouncement = message.body.startsWith('📅') || message.body.startsWith('🔒') || message.body.startsWith('🚫');

            const downloadUrl = message.attachment_url
              ? `${getApiBaseUrl()}${message.attachment_url}`
              : `${getApiBaseUrl()}/api/applications/${applicationId}/report/messages/${message.id}/attachment`;

            return (
              <div key={message.id} className={cn('flex flex-col', isMine ? 'items-end' : 'items-start')}>
                <div
                  className={cn(
                    'max-w-[82%] rounded-2xl px-4 py-3 text-xs leading-relaxed whitespace-pre-wrap shadow-2xs',
                    isSystemAnnouncement
                      ? 'bg-amber-50 text-amber-950 border border-amber-200'
                      : isMine
                        ? 'bg-[#C0272D] text-white rounded-br-none'
                        : 'bg-white text-[#0C1825] border border-[#E0E4E9] rounded-bl-none'
                  )}
                >
                  {message.body}

                  {/* Attachment Card */}
                  {message.has_attachment && (
                    <div className="mt-2 pt-2 border-t border-white/20">
                      <a
                        href={downloadUrl}
                        target="_blank"
                        rel="noreferrer"
                        className={cn(
                          'inline-flex items-center gap-2 p-2 rounded-xl text-xs font-medium transition-colors',
                          isMine
                            ? 'bg-white/10 hover:bg-white/20 text-white'
                            : 'bg-[#F4F6F8] hover:bg-[#E0E4E9] text-[#0C1825]'
                        )}
                      >
                        <FileText className="size-4 shrink-0" />
                        <span className="truncate max-w-[200px]">{message.attachment_name || 'Pièce jointe'}</span>
                        <Download className="size-3.5 shrink-0 opacity-70" />
                      </a>
                    </div>
                  )}
                </div>
                <span className="mt-1 text-[10px] text-muted-foreground px-1">
                  {message.sender_name} · {new Date(message.created_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}
                </span>
              </div>
            );
          })
        )}
        <div ref={bottomRef} />
      </div>

      {/* ── Chat Footer / Input ── */}
      <div className="border-t border-border/60 p-3 bg-white space-y-2">
        {sendError && (
          <p role="alert" className="px-1 text-xs text-destructive font-medium">
            {sendError}
          </p>
        )}

        {isClosed ? (
          <div className="p-3.5 bg-gray-100 border border-gray-200 rounded-xl text-xs text-gray-700 flex items-center gap-2.5">
            <Lock className="size-4 text-gray-500 shrink-0" />
            <div>
              <p className="font-bold text-[#0C1825]">Cette discussion a été clôturée par la banque.</p>
              <p className="text-[11px] text-gray-600">
                {closedReason ? `Motif : ${closedReason}` : 'Les échanges pour ce dossier sont terminés.'}
              </p>
            </div>
          </div>
        ) : isWaitingForStaffReply ? (
          <div className="p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900 flex items-center gap-2">
            <span className="size-2 rounded-full bg-amber-500 shrink-0 animate-pulse" />
            <span>
              Votre message a été transmis. Vous pourrez écrire à nouveau dès que votre conseiller ou administrateur vous aura répondu.
            </span>
          </div>
        ) : (
          <div className="space-y-2">
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

            <div className="flex items-end gap-2">
              <input
                ref={fileInputRef}
                type="file"
                onChange={handleFileSelect}
                accept={REPORT_ATTACHMENT_FORMATS}
                className="hidden"
                id={`file-attach-${applicationId}`}
              />

              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                className="btn-outline size-10 shrink-0 flex items-center justify-center rounded-xl hover:border-[#C0272D] hover:text-[#C0272D] transition-colors"
                title="Joindre un fichier (PDF, image, devis…)"
              >
                <Paperclip className="size-4" />
              </button>

              <Textarea
                id={`report-message-${applicationId}`}
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    handleSend();
                  }
                }}
                placeholder="Écrivez votre message ou joignez une pièce justificative…"
                className="min-h-10 text-xs flex-1 resize-none bg-[#FAFBFD] border-[#E0E4E9] focus-visible:ring-[#C0272D]"
                disabled={sending}
              />

              <Button
                size="icon"
                onClick={handleSend}
                disabled={sending || (!draft.trim() && !selectedFile)}
                aria-label={sending ? 'Envoi…' : 'Envoyer'}
                className="btn-red size-10 shrink-0"
              >
                {sending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
