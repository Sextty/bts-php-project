'use client';

import { Suspense, useEffect, useState, type FormEvent } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import Link from 'next/link';
import { AuthCard } from '@/components/auth-card';
import { ErrorAlert } from '@/components/error-alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { resetPassword } from '@/lib/api/auth';
import { ApiError } from '@/lib/api/client';

function ResetPasswordContent() {
  const router = useRouter();
  const params = useSearchParams();
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';

  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!token || !email) {
      router.replace('/forgot-password');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, email]);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);

    if (password !== passwordConfirmation) {
      setError('Les mots de passe ne correspondent pas.');
      return;
    }

    setSubmitting(true);
    try {
      await resetPassword({ token, email, password, password_confirmation: passwordConfirmation });
      router.push('/login');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Ce lien de réinitialisation est invalide ou expiré.');
    } finally {
      setSubmitting(false);
    }
  }

  if (!token || !email) return null;

  return (
    <AuthCard title="Nouveau mot de passe" description="Choisissez un mot de passe robuste et différent de vos anciens mots de passe.">
      <ErrorAlert message={error} />
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="password" className="font-semibold text-[#1e2d3d]">Nouveau mot de passe</Label>
          <Input
            id="password"
            type="password"
            required
            minLength={10}
            autoFocus
            autoComplete="new-password"
            aria-describedby="password-hint"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <p id="password-hint" className="text-xs text-muted-foreground">
            Utilisez au moins 10 caractères.
          </p>
        </div>
        <div className="space-y-2">
          <Label htmlFor="password_confirmation" className="font-semibold text-[#1e2d3d]">Confirmer le mot de passe</Label>
          <Input
            id="password_confirmation"
            type="password"
            required
            autoComplete="new-password"
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
          />
        </div>
        <Button type="submit" className="h-11 w-full bg-[#c0272d] font-semibold hover:bg-[#9e1f24]" disabled={submitting}>
          {submitting ? 'Mise à jour…' : 'Modifier le mot de passe'}
        </Button>
      </form>
      <p className="mt-4 text-center text-sm text-muted-foreground">
        <Link href="/login" className="font-medium text-primary underline">
          Retour à la connexion
        </Link>
      </p>
    </AuthCard>
  );
}

export default function ResetPasswordPage() {
  return (
    <Suspense>
      <ResetPasswordContent />
    </Suspense>
  );
}
