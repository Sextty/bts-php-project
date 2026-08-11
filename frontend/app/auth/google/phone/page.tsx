'use client';

import { Suspense, useState, type FormEvent } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { AuthCard } from '@/components/auth-card';
import { ErrorAlert } from '@/components/error-alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { googleSetPhone } from '@/lib/api/auth';
import { ApiError } from '@/lib/api/client';

function GooglePhoneContent() {
  const router = useRouter();
  const preAuthToken = useSearchParams().get('pre_auth_token') ?? '';
  const [phone, setPhone] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const result = await googleSetPhone({ pre_auth_token: preAuthToken, phone });
      router.push(`/auth/google/verify-otp?pre_auth_token=${encodeURIComponent(result.pre_auth_token)}`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthCard title="One more step" description="Add a phone number so we can verify your account.">
      <ErrorAlert message={error} />
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="phone">Phone number</Label>
          <Input
            id="phone"
            type="tel"
            placeholder="+21620000000"
            required
            autoFocus
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
          />
        </div>
        <Button type="submit" className="w-full" disabled={submitting}>
          {submitting ? 'Sending code…' : 'Send verification code'}
        </Button>
      </form>
    </AuthCard>
  );
}

export default function GooglePhonePage() {
  return (
    <Suspense>
      <GooglePhoneContent />
    </Suspense>
  );
}
