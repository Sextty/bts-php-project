'use client';

import { useRef, useState, useCallback } from 'react';
import { useRouter } from 'next/navigation';
import Script from 'next/script';
import { googleAuth } from '@/lib/api/auth';
import { setToken } from '@/lib/auth/token';
import { setPreAuthToken } from '@/lib/auth/pre-auth-token';
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

function GoogleLogo() {
  return (
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
      <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4" />
      <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
      <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05" />
      <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
    </svg>
  );
}

/**
 * Google Identity Services credential (ID-token) flow.
 *
 * Uses next/script `onReady` which fires both on first load AND on every component
 * remount — so the button survives SPA navigation (logout → /login) without any
 * module-level state hacks.
 */
export function GoogleSignInButton() {
  const router = useRouter();
  const buttonRef = useRef<HTMLDivElement>(null);
  const [error, setError] = useState<string | null>(null);

  const handleCredential = useCallback(async (response: { credential: string }) => {
    setError(null);
    try {
      const result = await googleAuth({ google_id_token: response.credential });
      if ('access_token' in result) {
        setToken(result.access_token);
        router.push('/dashboard');
      } else if ('requires_phone' in result) {
        setPreAuthToken(result.pre_auth_token);
        router.push('/auth/google/phone');
      } else {
        setPreAuthToken(result.pre_auth_token);
        router.push('/auth/google/verify-otp');
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Google sign-in failed.');
    }
  }, [router]);

  const renderGoogleButton = useCallback(() => {
    if (!window.google || !buttonRef.current) return;

    // Clear any leftover button from a previous mount to prevent duplicates.
    buttonRef.current.innerHTML = '';

    window.google.accounts.id.initialize({
      client_id: CLIENT_ID,
      callback: handleCredential,
    });

    const width = window.innerWidth < 360 ? window.innerWidth - 48 : 320;
    window.google.accounts.id.renderButton(buttonRef.current, { theme: 'outline', size: 'large', width });
  }, [handleCredential]);

  function handleMissingConfig() {
    setError('Google Sign-In is not configured. Set NEXT_PUBLIC_GOOGLE_CLIENT_ID in your .env.local file.');
  }

  if (!CLIENT_ID) {
    return (
      <div className="space-y-2">
        <div className="relative my-2">
          <div className="absolute inset-0 flex items-center">
            <div className="w-full border-t border-border" />
          </div>
          <div className="relative flex justify-center text-xs uppercase">
            <span className="bg-card px-2 text-muted-foreground">ou</span>
          </div>
        </div>
        <button
          type="button"
          onClick={handleMissingConfig}
          className="flex h-12 w-full items-center justify-center gap-3 rounded-lg border border-input bg-background px-4 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
        >
          <GoogleLogo />
          Continuer avec Google
        </button>
        {error && (
          <p className="text-center text-xs text-destructive">{error}</p>
        )}
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <div className="relative my-2">
        <div className="absolute inset-0 flex items-center">
          <div className="w-full border-t border-border" />
        </div>
        <div className="relative flex justify-center text-xs uppercase">
          <span className="bg-card px-2 text-muted-foreground">ou</span>
        </div>
      </div>
      <Script
        src="https://accounts.google.com/gsi/client"
        strategy="afterInteractive"
        onReady={renderGoogleButton}
        onError={() => setError('Failed to load Google Sign-In. Check your network connection.')}
      />
      {error && (
        <p className="text-center text-xs text-destructive">{error}</p>
      )}
      <div ref={buttonRef} className="flex justify-center" />
    </div>
  );
}
