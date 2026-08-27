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
import { ArrowRight, LockKeyhole, Mail, Phone, UserRound } from 'lucide-react';
import { register } from '@/lib/api/auth';
import { ApiError } from '@/lib/api/client';
import { setPreAuthToken } from '@/lib/auth/pre-auth-token';

export default function RegisterPage() {
  const router = useRouter();
  const [form, setForm] = useState({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
  });
  const [error, setError] = useState<string | null>(null);
  const [errorFields, setErrorFields] = useState<Record<string, string[]> | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setErrorFields(null);

    if (form.password !== form.password_confirmation) {
      setError('Les mots de passe ne correspondent pas.');
      return;
    }

    setSubmitting(true);
    try {
      const result = await register(form);
      setPreAuthToken(result.pre_auth_token);

      router.push('/register/verify-otp');
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
    <AuthCard title="Créer votre espace" description="Quelques informations suffisent pour démarrer votre demande de financement.">
      <ErrorAlert message={error} fields={errorFields} />
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="first_name" className="text-sm font-semibold text-[#1e2d3d]">Prénom</Label>
            <div className="relative"><UserRound className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#6a7a8b]" aria-hidden="true" /><Input id="first_name" required autoComplete="given-name" className="h-11 border-[#d7dfe7] pl-10 shadow-sm focus-visible:border-[#c0272d] focus-visible:ring-[#c0272d]/20" value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} /></div>
          </div>
          <div className="space-y-2">
            <Label htmlFor="last_name" className="text-sm font-semibold text-[#1e2d3d]">Nom</Label>
            <Input id="last_name" required autoComplete="family-name" className="h-11 border-[#d7dfe7] shadow-sm focus-visible:border-[#c0272d] focus-visible:ring-[#c0272d]/20" value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} />
          </div>
        </div>
        <div className="space-y-2">
          <Label htmlFor="email" className="text-sm font-semibold text-[#1e2d3d]">Adresse e-mail</Label>
          <div className="relative"><Mail className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#6a7a8b]" aria-hidden="true" /><Input id="email" type="email" required autoComplete="email" className="h-11 border-[#d7dfe7] pl-10 shadow-sm focus-visible:border-[#c0272d] focus-visible:ring-[#c0272d]/20" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></div>
        </div>
        <div className="space-y-2">
          <Label htmlFor="phone" className="text-sm font-semibold text-[#1e2d3d]">Téléphone</Label>
          <div className="relative"><Phone className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#6a7a8b]" aria-hidden="true" /><Input id="phone" type="tel" placeholder="+216 20 000 000" required autoComplete="tel" className="h-11 border-[#d7dfe7] pl-10 shadow-sm focus-visible:border-[#c0272d] focus-visible:ring-[#c0272d]/20" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></div>
        </div>
        <div className="space-y-2">
          <Label htmlFor="password" className="text-sm font-semibold text-[#1e2d3d]">Mot de passe</Label>
          <div className="relative"><LockKeyhole className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#6a7a8b]" aria-hidden="true" /><Input id="password" type="password" required minLength={10} autoComplete="new-password" aria-describedby="password-hint" className="h-11 border-[#d7dfe7] pl-10 shadow-sm focus-visible:border-[#c0272d] focus-visible:ring-[#c0272d]/20" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} /></div>
          <p id="password-hint" className="text-xs text-muted-foreground">
            Utilisez au moins 10 caractères.
          </p>
        </div>
        <div className="space-y-2">
          <Label htmlFor="password_confirmation" className="text-sm font-semibold text-[#1e2d3d]">Confirmer le mot de passe</Label>
          <Input id="password_confirmation" type="password" required autoComplete="new-password" className="h-11 border-[#d7dfe7] shadow-sm focus-visible:border-[#c0272d] focus-visible:ring-[#c0272d]/20" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} />
        </div>
        <Button type="submit" className="h-11 w-full bg-[#c0272d] text-sm font-semibold hover:bg-[#9e1f24]" disabled={submitting}>
          {submitting ? 'Création en cours…' : <>Créer mon compte <ArrowRight className="size-4" aria-hidden="true" /></>}
        </Button>
      </form>
      <GoogleSignInButton />
      <p className="mt-6 text-center text-sm text-[#536579]">
        Vous avez déjà un compte ?{' '}
        <Link href="/login" className="font-semibold text-[#a82027] underline-offset-4 hover:underline">
          Se connecter
        </Link>
      </p>
    </AuthCard>
  );
}
