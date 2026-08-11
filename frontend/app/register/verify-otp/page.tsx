'use client';

import { Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { AuthCard } from '@/components/auth-card';
import { OtpVerifyForm } from '@/components/otp-verify-form';
import { verifyRegistrationOtp } from '@/lib/api/auth';
import { setToken } from '@/lib/auth/token';

function VerifyOtpContent() {
  const router = useRouter();
  const preAuthToken = useSearchParams().get('pre_auth_token') ?? '';

  async function handleVerify(otpCode: string) {
    const result = await verifyRegistrationOtp({ pre_auth_token: preAuthToken, otp_code: otpCode });
    setToken(result.access_token);
    router.push('/dashboard');
  }

  return (
    <AuthCard title="Verify your phone" description="Enter the 6-digit code we texted you.">
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
