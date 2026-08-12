'use client';

import { useEffect, useRef, useState } from 'react';
import { Send } from 'lucide-react';
import { AuthCard } from '@/components/auth-card';
import { ErrorAlert } from '@/components/error-alert';
import { buttonVariants } from '@/components/ui/button';
import { checkTelegramLink } from '@/lib/api/auth';
import { ApiError } from '@/lib/api/client';
import { cn } from '@/lib/utils';

const POLL_INTERVAL_MS = 2500;

/**
 * The one-time Telegram handshake. Telegram bots can't message anyone who hasn't started a
 * conversation with them first, so a new account has nowhere to receive a code until the user
 * presses Start. This screen hands them the deep link and polls until that happens — the backend
 * dispatches the OTP on the same request that detects it.
 */
export function TelegramLinkForm({
  preAuthToken,
  linkUrl,
  purpose,
  onLinked,
}: {
  preAuthToken: string;
  linkUrl: string;
  purpose: 'registration' | 'login';
  onLinked: () => void;
}) {
  const [error, setError] = useState<string | null>(null);
  const [waiting, setWaiting] = useState(false);
  // Ref, not state: the interval callback closes over its first render, so a state flag would
  // always read false there and let a second navigation fire.
  const doneRef = useRef(false);

  useEffect(() => {
    const timer = setInterval(async () => {
      if (doneRef.current) return;
      try {
        const { linked } = await checkTelegramLink({ pre_auth_token: preAuthToken, purpose });
        if (linked && !doneRef.current) {
          doneRef.current = true;
          clearInterval(timer);
          onLinked();
        }
      } catch (err) {
        doneRef.current = true;
        clearInterval(timer);
        setError(err instanceof ApiError ? err.message : 'Something went wrong. Please start again.');
      }
    }, POLL_INTERVAL_MS);

    return () => clearInterval(timer);
  }, [preAuthToken, purpose, onLinked]);

  return (
    <AuthCard
      title="Connect Telegram"
      description="We send your verification code over Telegram. This one-time step lets us reach you."
    >
      <ErrorAlert message={error} />
      <div className="space-y-4">
        <ol className="space-y-2 text-sm text-muted-foreground">
          <li>1. Open our bot with the button below.</li>
          <li>
            2. Press <span className="font-medium text-foreground">Start</span> in Telegram.
          </li>
          <li>3. Come back here — we&apos;ll send your code automatically.</li>
        </ol>

        <a
          href={linkUrl}
          target="_blank"
          rel="noopener noreferrer"
          onClick={() => setWaiting(true)}
          className={cn(buttonVariants({ variant: 'default' }), 'w-full gap-2')}
        >
          <Send className="size-4" />
          Open Telegram
        </a>

        <p className="text-center text-xs text-muted-foreground">
          {waiting ? 'Waiting for you to press Start…' : 'Waiting for connection…'}
        </p>
      </div>
    </AuthCard>
  );
}
