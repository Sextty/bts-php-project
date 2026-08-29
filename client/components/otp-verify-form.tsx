'use client';

import { useState, type FormEvent } from 'react';
import { ErrorAlert } from '@/components/error-alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowRight, KeyRound } from 'lucide-react';
import { ApiError } from '@/lib/api/client';

/** Shared by every OTP-verify step (registration, login, Google phone-verification). */
export function OtpVerifyForm({
  onVerify,
  submitLabel = 'Vérifier',
  description,
}: {
  onVerify: (otpCode: string) => Promise<void>;
  submitLabel?: string;
  description?: string;
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
      setError(err instanceof ApiError ? err.message : 'Une erreur est survenue. Veuillez réessayer.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <ErrorAlert message={error} />
      <form onSubmit={handleSubmit} className="space-y-5">
        <div className="space-y-2">
          <Label htmlFor="otp_code" className="text-sm font-semibold text-[#1e2d3d]">Code à 6 chiffres</Label>
          <div className="relative">
            <KeyRound className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#6a7a8b]" aria-hidden="true" />
            <Input
              id="otp_code"
              inputMode="numeric"
              pattern="\d{6}"
              maxLength={6}
              required
              autoFocus
              autoComplete="one-time-code"
              aria-describedby="otp-hint"
              value={otpCode}
              onChange={(e) => setOtpCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
              className="h-12 pl-10 text-center font-mono text-lg font-bold tracking-[0.45em]"
            />
          </div>
          {description && (
            <p id="otp-hint" className="text-xs text-muted-foreground">
              {description}
            </p>
          )}
        </div>
        <Button type="submit" className="h-11 w-full bg-[#c0272d] font-semibold hover:bg-[#9e1f24]" disabled={submitting || otpCode.length !== 6}>
          {submitting ? 'Vérification…' : <>{submitLabel} <ArrowRight className="size-4" aria-hidden="true" /></>}
        </Button>
      </form>
    </>
  );
}
