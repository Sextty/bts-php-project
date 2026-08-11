'use client';

import { useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import Script from 'next/script';
import { ErrorAlert } from '@/components/error-alert';
import { googleAuth } from '@/lib/api/auth';
import { setToken } from '@/lib/auth/token';
import { ApiError } from '@/lib/api/client';

declare global {
  interface Window {
    google?: {
      accounts: {
        id: {
          initialize: (config: { client_id: string; callback: (response: { credential: string }) => void }) => void;
          renderButton: (parent: HTMLElement, options: { theme: string; size: string; width: number }) => void;
        };
      };
    };
  }
}

const CLIENT_ID = process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID ?? '';

/**
 * Google Identity Services' credential (ID-token) flow — the button hands back a signed
 * google_id_token directly in the browser, no server-side redirect/callback route needed (that
 * pattern belongs to the authorization-code flow, not this one). The token goes straight to
 * POST /auth/google, which is the only thing that ever decides whether it's trusted.
 */
export function GoogleSignInButton() {
  const router = useRouter();
  const buttonRef = useRef<HTMLDivElement>(null);
  const [error, setError] = useState<string | null>(null);
  const [scriptLoaded, setScriptLoaded] = useState(false);

  useEffect(() => {
    if (!scriptLoaded || !window.google || !buttonRef.current || !CLIENT_ID) return;

    window.google.accounts.id.initialize({
      client_id: CLIENT_ID,
      callback: async (response) => {
        setError(null);
        try {
          const result = await googleAuth({ google_id_token: response.credential });
          if ('access_token' in result) {
            setToken(result.access_token);
            router.push('/dashboard');
          } else if ('requires_phone' in result) {
            router.push(`/auth/google/phone?pre_auth_token=${encodeURIComponent(result.pre_auth_token)}`);
          } else {
            router.push(`/auth/google/verify-otp?pre_auth_token=${encodeURIComponent(result.pre_auth_token)}`);
          }
        } catch (err) {
          setError(err instanceof ApiError ? err.message : 'Google sign-in failed.');
        }
      },
    });

    window.google.accounts.id.renderButton(buttonRef.current, { theme: 'outline', size: 'large', width: 320 });
  }, [scriptLoaded, router]);

  if (!CLIENT_ID) {
    return null;
  }

  return (
    <div>
      <Script src="https://accounts.google.com/gsi/client" async defer onLoad={() => setScriptLoaded(true)} />
      <ErrorAlert message={error} />
      <div ref={buttonRef} className="flex justify-center" />
    </div>
  );
}
