'use client';

import { useState, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { AuthCard } from '@/components/auth-card';
import { ErrorAlert } from '@/components/error-alert';
import { GoogleSignInButton } from '@/components/google-sign-in-button';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowRight, KeyRound, Mail } from 'lucide-react';
import { login } from '@/lib/api/auth';
import { ApiError } from '@/lib/api/client';
import { setPreAuthToken } from '@/lib/auth/pre-auth-token';

export default function LoginPage() {
  const router = useRouter();
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [errorFields, setErrorFields] = useState<Record<string, string[]> | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setErrorFields(null);
    setSubmitting(true);
    try {
      const result = await login({ identifier, password });
      setPreAuthToken(result.pre_auth_token);

      router.push('/login/verify-otp');
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
        setErrorFields(err.fields ?? null);
      } else {
        setError('Une erreur est survenue. Veuillez réessayer.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthCard title="Bienvenue" description="Connectez-vous pour suivre votre demande et échanger avec votre agence.">
      <ErrorAlert message={error} fields={errorFields} />
      <form onSubmit={handleSubmit} className="space-y-5">
        <div className="space-y-2">
          <Label htmlFor="identifier" className="text-sm font-semibold text-[#1e2d3d]">E-mail ou téléphone</Label>
          <div className="relative">
            <Mail className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#6a7a8b]" aria-hidden="true" />
            <Input id="identifier" required autoComplete="username" placeholder="nom@exemple.tn ou +216..." className="h-11 border-[#d7dfe7] pl-10 shadow-sm focus-visible:border-[#c0272d] focus-visible:ring-[#c0272d]/20" value={identifier} onChange={(e) => setIdentifier(e.target.value)} />
          </div>
        </div>
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label htmlFor="password" className="text-sm font-semibold text-[#1e2d3d]">Mot de passe</Label>
            <Link href="/forgot-password" className="text-xs font-semibold text-[#a82027] underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#c0272d]">
              Mot de passe oublié ?
            </Link>
          </div>
          <div className="relative">
            <KeyRound className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#6a7a8b]" aria-hidden="true" />
            <Input id="password" type="password" required autoComplete="current-password" className="h-11 border-[#d7dfe7] pl-10 shadow-sm focus-visible:border-[#c0272d] focus-visible:ring-[#c0272d]/20" value={password} onChange={(e) => setPassword(e.target.value)} />
          </div>
        </div>
        <Button type="submit" className="h-11 w-full bg-[#c0272d] text-sm font-semibold hover:bg-[#9e1f24]" disabled={submitting}>
          {submitting ? 'Connexion en cours…' : <>Continuer <ArrowRight className="size-4" aria-hidden="true" /></>}
        </Button>
      </form>
      <GoogleSignInButton />
      <p className="mt-6 text-center text-sm text-[#536579]">
        Nouveau chez BTS Bank ?{' '}
        <Link href="/register" className="font-semibold text-[#a82027] underline-offset-4 hover:underline">
          Créer un compte
        </Link>
      </p>
    </AuthCard>
  );
}
