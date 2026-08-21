'use client';

import { Suspense, useEffect } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { AuthCard } from '@/components/auth-card';
import { OtpVerifyForm } from '@/components/otp-verify-form';
import { verifyRegistrationOtp } from '@/lib/api/auth';
import { setToken } from '@/lib/auth/token';
import { clearPreAuthToken, getPreAuthToken } from '@/lib/auth/pre-auth-token';

function VerifyOtpContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  // sessionStorage is the primary carrier now; the query-string fallback keeps old
  // links (and the registration flow's email link) working.
  const preAuthToken = getPreAuthToken() || (searchParams.get('pre_auth_token') ?? '');

  useEffect(() => {
    if (!preAuthToken) {
      router.replace('/register');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [preAuthToken]);

  async function handleVerify(otpCode: string) {
    const result = await verifyRegistrationOtp({ pre_auth_token: preAuthToken, otp_code: otpCode });
    clearPreAuthToken();
    setToken(result.access_token);
    router.push('/dashboard');
  }

  if (!preAuthToken) return null;

  return (
    <AuthCard title="Verify your account" description="Enter the 6-digit code we sent to your email or phone.">
      <OtpVerifyForm onVerify={handleVerify} submitLabel="Verify and continue" />
    </AuthCard>
  );
}

export default function RegisterVerifyOtpPage() {
  return (
    <Suspense>
      <VerifyOtpContent />
    </Suspense>
  );
}
