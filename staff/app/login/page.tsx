'use client';

import { useState, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import { Logo } from '@/components/logo';
import { ErrorAlert } from '@/components/error-alert';
import { staffLogin } from '@/lib/api/staff';
import { setStaffRole, setStaffToken } from '@/lib/auth/staff-token';
import { ApiError } from '@/lib/api/client';
import { Lock, Mail, ArrowRight, ShieldCheck } from 'lucide-react';

export default function StaffLoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const result = await staffLogin({ email, password });
      setStaffToken(result.access_token);
      setStaffRole(result.staff_user.role);
      router.push('/dashboard');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Identifiants invalides. Veuillez réessayer.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main id="main" className="staff-page flex min-h-screen flex-col justify-center py-10 text-[#1E2D3D] sm:px-6 lg:px-8">
      <div className="sm:mx-auto sm:w-full sm:max-w-md text-center space-y-3">
        <div className="inline-flex items-center justify-center gap-3">
          <Logo size={40} />
          <span className="font-display text-2xl font-semibold tracking-tight text-[#0C1825]">
            BTS <span className="font-sans font-normal text-sm text-[#3D5166]">Bank</span>
          </span>
        </div>
        <div className="space-y-1">
          <p className="overline">Portail Professionnel Sécurisé</p>
          <h1 className="font-display text-2xl font-light text-[#0C1825]">
            Connexion Conseillers & Administration
          </h1>
          <p className="text-xs text-[#3D5166]">
            Accès interne réservé au personnel habilité de la Banque Tunisienne de Solidarité.
          </p>
        </div>
      </div>

      <div className="mt-6 sm:mx-auto sm:w-full sm:max-w-md px-4">
        <div className="figma-card space-y-6 border-t-4 border-t-[#C0272D] bg-white/95 p-6 shadow-xl shadow-slate-900/5 backdrop-blur sm:p-8">
          <ErrorAlert message={error} />

          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-1.5">
              <label htmlFor="email" className="text-xs font-semibold text-[#0C1825] flex items-center gap-1.5">
                <Mail className="size-3.5 text-[#C0272D]" />
                <span>Adresse Email Professionnelle</span>
              </label>
              <input
                id="email"
                type="email"
                required
                autoFocus
                autoComplete="email"
                placeholder="agent@bts.com.tn"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3.5 py-2.5 text-[#0C1825] placeholder:text-gray-400 focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D] transition-colors"
              />
            </div>

            <div className="space-y-1.5">
              <label htmlFor="password" className="text-xs font-semibold text-[#0C1825] flex items-center gap-1.5">
                <Lock className="size-3.5 text-[#C0272D]" />
                <span>Mot de Passe Sécurisé</span>
              </label>
              <input
                id="password"
                type="password"
                required
                autoComplete="current-password"
                placeholder="••••••••••••"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3.5 py-2.5 text-[#0C1825] placeholder:text-gray-400 focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D] transition-colors"
              />
            </div>

            <div className="pt-2">
              <button
                type="submit"
                disabled={submitting}
                className="btn-red w-full px-6 py-2.5 text-xs shadow-xs"
              >
                <span>{submitting ? 'Authentification en cours…' : 'Accéder au portail staff'}</span>
                <ArrowRight className="size-4" />
              </button>
            </div>
          </form>

          <div className="p-3 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] flex items-center gap-2 text-[11px] text-[#3D5166]">
            <ShieldCheck className="size-4 text-emerald-600 shrink-0" />
            <span>Toutes les connexions sont tracées et journalisées dans le registre d&apos;audit.</span>
          </div>
        </div>
      </div>
    </main>
  );
}
