'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Shield, Lock, Mail, Loader2, Eye, EyeOff } from 'lucide-react';
import { Logo } from '@/components/logo';
import { apiFetch } from '@/lib/api/client';
import { setSecurityRole, setSecurityToken, setSecurityUser } from '@/lib/auth/security-token';
import { getErrorMessage } from '@/lib/api/client';
import { StatusMessage } from '@/components/status-message';

export default function SecurityLoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const response = await apiFetch<{
        access_token: string;
        staff_user: {
          id: number;
          first_name: string;
          last_name: string;
          email: string;
          role: 'security' | 'admin' | 'super_admin';
        };
      }>('/security/auth/login', {
        method: 'POST',
        body: { email, password },
        auth: false,
      });

      setSecurityToken(response.access_token);
      setSecurityRole(response.staff_user.role);
      setSecurityUser(response.staff_user);

      router.push('/');
    } catch (loginError: unknown) {
      setError(getErrorMessage(loginError, 'Identifiants invalides ou accès non autorisé.'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <main id="main" className="sc-root relative flex min-h-screen items-center justify-center overflow-hidden p-4">
      <div aria-hidden="true" className="pointer-events-none absolute -right-32 -top-32 size-96 rounded-full bg-red-100/70 blur-3xl" />
      <div className="relative w-full max-w-md rounded-3xl border border-white/80 bg-white/90 p-7 shadow-[0_24px_70px_rgba(15,23,42,0.12)] backdrop-blur-xl sm:p-8">
        {/* Brand & Title */}
        <div className="text-center mb-8">
          <div className="flex justify-center mb-4">
            <Logo size={64} />
          </div>
          <h1 className="text-xl font-bold text-[#0C1825] tracking-tight">BTS BANK — SECURITY CENTER</h1>
          <div className="flex items-center justify-center gap-1.5 mt-1.5">
            <Shield className="size-3.5 text-[#C0272D]" />
            <span className="text-xs font-semibold uppercase tracking-wider text-[#C0272D]">
              Portail Opérateur Sécurité & SOC (Port 3003)
            </span>
          </div>
        </div>

        <StatusMessage message={error} className="mb-6" />

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label htmlFor="security-email" className="block text-xs font-semibold text-[#3D5166] mb-1.5">
              Adresse e-mail Sécurité
            </label>
            <div className="relative">
              <Mail className="size-4 text-[#8C9BAE] absolute left-3.5 top-3" />
              <input
                id="security-email"
                name="email"
                type="email"
                required
                autoFocus
                autoComplete="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="security@btsbank.tn"
                className="sc-input pl-10"
              />
            </div>
          </div>

          <div>
            <label htmlFor="security-password" className="block text-xs font-semibold text-[#3D5166] mb-1.5">
              Mot de passe
            </label>
            <div className="relative">
              <Lock className="size-4 text-[#8C9BAE] absolute left-3.5 top-3" />
              <input
                id="security-password"
                name="password"
                type={showPassword ? 'text' : 'password'}
                required
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••••••"
                className="sc-input pl-10 pr-11"
              />
              <button
                type="button"
                onClick={() => setShowPassword((visible) => !visible)}
                aria-label={showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
                className="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1 text-slate-500 hover:text-slate-950"
              >
                {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
              </button>
            </div>
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full mt-2 py-3 px-4 rounded-xl bg-[#C0272D] hover:bg-[#A01F24] text-white text-xs font-bold uppercase tracking-wider transition-all duration-150 flex items-center justify-center gap-2 shadow-sm disabled:opacity-50"
          >
            {loading ? (
              <>
                <Loader2 className="size-4 animate-spin" />
                <span>Vérification de l’Accès…</span>
              </>
            ) : (
              <>
                <Shield className="size-4" />
                <span>Connexion Sécurisée</span>
              </>
            )}
          </button>
        </form>

        <div className="mt-8 pt-4 border-t border-[#E0E4E9] text-center">
          <p className="text-[11px] text-[#8C9BAE]">
            Accès strictement réservé aux analystes SOC et membres habilités de l’Équipe Sécurité BTS Bank.
          </p>
        </div>
      </div>
    </main>
  );
}
