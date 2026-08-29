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
import { getPreAuthToken, setPreAuthToken } from '@/lib/auth/pre-auth-token';

function GooglePhoneContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const preAuthToken = getPreAuthToken() || (searchParams.get('pre_auth_token') ?? '');
  const [phone, setPhone] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const result = await googleSetPhone({ pre_auth_token: preAuthToken, phone });
      setPreAuthToken(result.pre_auth_token);
      router.push('/auth/google/verify-otp');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Une erreur est survenue. Veuillez réessayer.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthCard title="Dernière étape" description="Ajoutez votre numéro de téléphone pour sécuriser et vérifier votre compte.">
      <ErrorAlert message={error} />
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="phone" className="font-semibold text-[#1e2d3d]">Numéro de téléphone</Label>
          <Input
            id="phone"
            type="tel"
            placeholder="+216 20 000 000"
            required
            autoFocus
            autoComplete="tel"
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
          />
        </div>
        <Button type="submit" className="h-11 w-full bg-[#c0272d] font-semibold hover:bg-[#9e1f24]" disabled={submitting}>
          {submitting ? 'Envoi du code…' : 'Envoyer le code de vérification'}
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
