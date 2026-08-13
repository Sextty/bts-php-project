'use client';

import { Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { AuthCard } from '@/components/auth-card';
import { OtpVerifyForm } from '@/components/otp-verify-form';
import { googleVerifyOtp } from '@/lib/api/auth';
import { setToken } from '@/lib/auth/token';

function VerifyOtpContent() {
  const router = useRouter();
  const preAuthToken = useSearchParams().get('pre_auth_token') ?? '';

  async function handleVerify(otpCode: string) {
    const result = await googleVerifyOtp({ pre_auth_token: preAuthToken, otp_code: otpCode });
    setToken(result.access_token);
    router.push('/dashboard');
  }

  return (
    <AuthCard title="Verify your phone" description="Enter the 6-digit code we sent to your email.">
      <OtpVerifyForm onVerify={handleVerify} submitLabel="Verify and continue" />
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
