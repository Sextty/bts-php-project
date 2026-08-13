'use client';

import { Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { AuthCard } from '@/components/auth-card';
import { OtpVerifyForm } from '@/components/otp-verify-form';
import { verifyLoginOtp } from '@/lib/api/auth';
import { setToken } from '@/lib/auth/token';

function VerifyOtpContent() {
  const router = useRouter();
  const preAuthToken = useSearchParams().get('pre_auth_token') ?? '';

  async function handleVerify(otpCode: string) {
    const result = await verifyLoginOtp({ pre_auth_token: preAuthToken, otp_code: otpCode });
    setToken(result.access_token);
    router.push('/dashboard');
  }

  return (
    <AuthCard title="Enter your code" description="We sent a 6-digit code to your email.">
      <OtpVerifyForm onVerify={handleVerify} submitLabel="Log in" />
    </AuthCard>
  );
}

export default function LoginVerifyOtpPage() {
  return (
    <Suspense>
      <VerifyOtpContent />
    </Suspense>
  );
}
