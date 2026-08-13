'use client';

import { useEffect, useRef, useState } from 'react';
import type Echo from 'laravel-echo';
import { Send } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { ReportMessageDto } from '@/lib/api/reports';
import { createEchoClient } from '@/lib/echo';

interface ReportChatProps {
  applicationId: number;
  currentSenderType: 'customer' | 'staff';
  getToken: () => string | null;
  initialMessages: ReportMessageDto[];
  onSend: (body: string) => Promise<ReportMessageDto>;
}

/**
 * Shared by both the customer and staff pages — same channel, same event, only which side of the
 * bubble a message renders on differs (based on `currentSenderType`).
 */
export function ReportChat({ applicationId, currentSenderType, getToken, initialMessages, onSend }: ReportChatProps) {
  const [messages, setMessages] = useState<ReportMessageDto[]>(initialMessages);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [connected, setConnected] = useState(false);
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const echo = createEchoClient(getToken) as Echo<'reverb'>;
    const channel = echo.private(`application.${applicationId}.report`);

    channel.listen('.report.message', (event: ReportMessageDto) => {
      setMessages((prev) => (prev.some((m) => m.id === event.id) ? prev : [...prev, event]));
    });
    channel.subscribed(() => setConnected(true));
    channel.error(() => setConnected(false));

    return () => {
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
    if (!body || sending) return;

    setSending(true);
    setDraft('');
    try {
      const message = await onSend(body);
      setMessages((prev) => (prev.some((m) => m.id === message.id) ? prev : [...prev, message]));
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="flex h-[28rem] flex-col rounded-lg border border-border/60 bg-card">
      <div className="flex items-center justify-between border-b border-border/60 px-3 py-2">
        <span className="text-xs font-medium text-muted-foreground">Conversation</span>
        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
          <span className={cn('size-1.5 rounded-full', connected ? 'bg-emerald-500' : 'bg-muted-foreground/40')} />
          {connected ? 'Live' : 'Connecting…'}
        </span>
      </div>

      <div className="flex-1 space-y-2 overflow-y-auto px-3 py-3">
        {messages.length === 0 ? (
          <p className="py-8 text-center text-sm text-muted-foreground">No messages yet.</p>
        ) : (
          messages.map((message) => {
            const isMine = message.sender_type === currentSenderType;
            return (
              <div key={message.id} className={cn('flex flex-col', isMine ? 'items-end' : 'items-start')}>
                <div
                  className={cn(
                    'max-w-[75%] rounded-lg px-3 py-2 text-sm',
                    isMine ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground'
                  )}
                >
                  {message.body}
                </div>
                <span className="mt-0.5 text-[0.7rem] text-muted-foreground">
                  {message.sender_name} · {new Date(message.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                </span>
              </div>
            );
          })
        )}
        <div ref={bottomRef} />
      </div>

      <div className="flex items-end gap-2 border-t border-border/60 p-2">
        <Textarea
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              handleSend();
            }
          }}
          placeholder="Write a message…"
          className="min-h-9 flex-1 resize-none"
        />
        <Button size="icon" onClick={handleSend} disabled={sending || !draft.trim()}>
          <Send className="size-4" />
        </Button>
      </div>
    </div>
  );
}
