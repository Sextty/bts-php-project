'use client';

import { useState, type FormEvent } from 'react';
import { ErrorAlert } from '@/components/error-alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError } from '@/lib/api/client';

/** Shared by every OTP-verify step (registration, login, Google phone-verification). */
export function OtpVerifyForm({
  onVerify,
  submitLabel = 'Verify',
}: {
  onVerify: (otpCode: string) => Promise<void>;
  submitLabel?: string;
}) {
  const [otpCode, setOtpCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await onVerify(otpCode);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <ErrorAlert message={error} />
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="otp_code">6-digit code</Label>
          <Input
            id="otp_code"
            inputMode="numeric"
            pattern="\d{6}"
            maxLength={6}
            required
            autoFocus
            value={otpCode}
            onChange={(e) => setOtpCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
            className="text-center text-lg tracking-[0.5em]"
          />
        </div>
        <Button type="submit" className="w-full" disabled={submitting || otpCode.length !== 6}>
          {submitting ? 'Verifying…' : submitLabel}
        </Button>
      </form>
    </>
  );
}
