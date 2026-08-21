'use client';

import { useState, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import { Eye, EyeOff, Shield, Loader2, Lock } from 'lucide-react';
import { AuthCard } from '@/components/auth-card';
import { ErrorAlert } from '@/components/error-alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { adminLogin } from '@/lib/api/staff';
import { setStaffRole, setStaffToken } from '@/lib/auth/staff-token';
import { ApiError } from '@/lib/api/client';

export default function AdminLoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const result = await adminLogin({ email, password });
      setStaffToken(result.access_token);
      setStaffRole(result.staff_user.role);
      router.push('/');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Une erreur est survenue. Veuillez réessayer.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main id="main" className="relative flex min-h-screen items-center justify-center overflow-hidden bg-[#F4F6F8] p-4">
      {/* Decorative background */}
      <div aria-hidden className="pointer-events-none absolute -top-40 -right-40 h-96 w-96 rounded-full bg-[#C0272D]/8 blur-3xl" />
      <div aria-hidden className="pointer-events-none absolute -bottom-40 -left-40 h-96 w-96 rounded-full bg-[#C0272D]/5 blur-3xl" />
      <div aria-hidden className="pointer-events-none absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-[600px] w-[600px] rounded-full bg-[#C0272D]/3 blur-[120px]" />

      <div className="relative flex w-full max-w-md flex-col items-center gap-8">
        {/* Brand */}
        <div className="flex flex-col items-center gap-3">
          <div className="size-16 rounded-2xl bg-white shadow-sm border border-[#E0E4E9] flex items-center justify-center">
            <Shield className="size-8 text-[#C0272D]" />
          </div>
          <div className="text-center">
            <p className="text-lg font-bold tracking-tight text-[#0C1825]">
              BTS <span className="font-normal text-[#3D5166]">Bank</span>
            </p>
            <div className="flex items-center justify-center gap-1.5 mt-1">
              <Lock className="size-3 text-[#C0272D]" />
              <span className="text-[10px] font-bold uppercase tracking-widest text-[#C0272D]">Administration</span>
            </div>
          </div>
        </div>

        {/* Login Card */}
        <div className="w-full bg-white rounded-2xl border border-[#E0E4E9] shadow-sm p-8">
          <div className="mb-6">
            <h1 className="text-xl font-bold text-[#0C1825]">Connexion Administrateur</h1>
            <p className="text-sm text-[#3D5166] mt-1">
              Accès réservé aux administrateurs BTS Bank.
            </p>
          </div>

          <ErrorAlert message={error} />

          <form onSubmit={handleSubmit} className="space-y-5 mt-4">
            <div className="space-y-2">
              <Label htmlFor="email" className="text-xs font-semibold text-[#0C1825]">
                Adresse e-mail
              </Label>
              <Input
                id="email"
                type="email"
                required
                autoFocus
                autoComplete="email"
                placeholder="admin@btsbank.tn"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="bg-[#F4F6F8] border-[#E0E4E9] focus:border-[#C0272D] focus:ring-[#C0272D]/20"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="password" className="text-xs font-semibold text-[#0C1825]">
                Mot de passe
              </Label>
              <div className="relative">
                <Input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  required
                  autoComplete="current-password"
                  placeholder="••••••••"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="bg-[#F4F6F8] border-[#E0E4E9] focus:border-[#C0272D] focus:ring-[#C0272D]/20 pr-10"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-[#3D5166] hover:text-[#0C1825] transition-colors"
                  aria-label={showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
                >
                  {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                </button>
              </div>
            </div>

            <Button
              type="submit"
              className="w-full bg-[#C0272D] hover:bg-[#A52025] text-white font-semibold py-2.5"
              disabled={submitting}
            >
              {submitting ? (
                <>
                  <Loader2 className="size-4 animate-spin mr-2" />
                  Connexion en cours…
                </>
              ) : (
                'Se connecter'
              )}
            </Button>
          </form>
        </div>

        <p className="text-[11px] text-[#3D5166]/60 text-center">
          © {new Date().getFullYear()} BTS Bank — Portail d'Administration Sécurisé
        </p>
      </div>
    </main>
  );
}