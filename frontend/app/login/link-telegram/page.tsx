'use client';

import { Suspense, useCallback } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { TelegramLinkForm } from '@/components/telegram-link-form';

function LinkTelegramContent() {
  const router = useRouter();
  const params = useSearchParams();
  const preAuthToken = params.get('pre_auth_token') ?? '';
  const linkUrl = params.get('link_url') ?? '';

  const handleLinked = useCallback(() => {
    router.push(`/login/verify-otp?pre_auth_token=${encodeURIComponent(preAuthToken)}`);
  }, [router, preAuthToken]);

  return (
    <TelegramLinkForm
      preAuthToken={preAuthToken}
      linkUrl={linkUrl}
      purpose="login"
      onLinked={handleLinked}
    />
  );
}

export default function LoginLinkTelegramPage() {
  return (
    <Suspense>
      <LinkTelegramContent />
    </Suspense>
  );
}
