'use client';

import { Suspense, useEffect } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { AuthCard } from '@/components/auth-card';
import { OtpVerifyForm } from '@/components/otp-verify-form';
import { googleVerifyOtp } from '@/lib/api/auth';
import { setToken } from '@/lib/auth/token';
import { clearPreAuthToken, getPreAuthToken } from '@/lib/auth/pre-auth-token';

function VerifyOtpContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  // sessionStorage is the primary carrier now; the query-string fallback keeps old
  // links working.
  const preAuthToken = getPreAuthToken() || (searchParams.get('pre_auth_token') ?? '');

  useEffect(() => {
    if (!preAuthToken) {
      router.replace('/login');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [preAuthToken]);

  async function handleVerify(otpCode: string) {
    const result = await googleVerifyOtp({ pre_auth_token: preAuthToken, otp_code: otpCode });
    clearPreAuthToken();
    setToken(result.access_token);
    router.push('/dashboard');
  }

  if (!preAuthToken) return null;

  return (
    <AuthCard title="Vérifiez votre téléphone" description="Saisissez le code à 6 chiffres envoyé sur votre téléphone.">
      <OtpVerifyForm onVerify={handleVerify} submitLabel="Vérifier et continuer" />
    </AuthCard>
  );
}

export default function GoogleVerifyOtpPage() {
  return (
    <Suspense>
      <VerifyOtpContent />
    </Suspense>
  );
}
