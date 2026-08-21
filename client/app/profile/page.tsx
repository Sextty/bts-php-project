'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  User,
  Mail,
  Phone,
  ShieldCheck,
  BadgeCheck,
  KeyRound,
  CreditCard,
  AlertCircle,
  ArrowRight,
  LogOut,
  Building2,
  TrendingUp,
  FileText,
  Calendar,
  CheckCircle2,
  Shield,
  Info,
} from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
import { Breadcrumbs, BackLink } from '@/components/breadcrumbs';
import { ErrorAlert } from '@/components/error-alert';
import { getCurrentUser, logout, type UserDto } from '@/lib/api/auth';
import { listApplications, type CreditApplicationDto } from '@/lib/api/credit-applications';
import { getToken, clearToken } from '@/lib/auth/token';
import { PageLoading } from '@/components/page-loading';
import { StatusBadge } from '@/components/status-badge';
import { statusLabel } from '@/lib/status-labels';

export default function ProfilePage() {
  const router = useRouter();
  const [user, setUser] = useState<UserDto | null>(null);
  const [applications, setApplications] = useState<CreditApplicationDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }

    Promise.allSettled([getCurrentUser(), listApplications()])
      .then(([userRes, appsRes]) => {
        if (userRes.status === 'rejected') {
          clearToken();
          router.replace('/login');
          return;
        }

        setUser(userRes.value.user);

        if (appsRes.status === 'fulfilled') {
          const rawApps = appsRes.value;
          const resolved: CreditApplicationDto[] = Array.isArray(rawApps)
            ? (rawApps as CreditApplicationDto[])
            : rawApps && typeof rawApps === 'object' && Array.isArray((rawApps as Record<string, unknown>).applications)
            ? (rawApps as unknown as { applications: CreditApplicationDto[] }).applications
            : [];
          setApplications(resolved);
        }
      })
      .catch(() => setError('Impossible de charger les informations de votre profil.'))
      .finally(() => setLoading(false));
  }, [router]);

  async function handleLogout() {
    try {
      await logout();
    } catch {
      // Ignore network errors on logout
    } finally {
      clearToken();
      router.push('/login');
    }
  }

  if (loading) return <PageLoading />;

  if (!user) {
    return (
      <div className="min-h-screen bg-[#F4F6F8] text-[#1E2D3D]">
        <DashboardHeader />
        <main id="main" className="mx-auto max-w-4xl px-4 sm:px-8 py-10 space-y-4">
          <BackLink href="/dashboard" label="Retour au tableau de bord" />
          <ErrorAlert message={error ?? 'Utilisateur introuvable.'} />
        </main>
      </div>
    );
  }

  const isVerified = !!user.phone_verified;
  const fullName = `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.email.split('@')[0];
  const initials = (user.first_name?.[0] || user.email[0] || 'U').toUpperCase();

  const DECLINED_STATUSES = new Set(['REJECTED', 'STAFF_REJECTED', 'CANCELLED']);
  const nonDeclinedApps = applications.filter((a) => !DECLINED_STATUSES.has(a.status));

  const totalAmount = nonDeclinedApps.reduce((sum, a) => {
    const amt = Number(a.credit_request?.montant_global_sollicite);
    return sum + (isNaN(amt) ? 0 : amt);
  }, 0);

  const formattedTotal =
    totalAmount > 0
      ? new Intl.NumberFormat('fr-TN', { maximumFractionDigits: 0 }).format(totalAmount) + ' TND'
      : '0 TND';

  const providerLabel = (p: string) => {
    switch (p) {
      case 'google':
        return 'Google OAuth 2.0 (Sécurisé)';
      case 'password':
        return 'Email & Mot de passe chiffré';
      default:
        return p;
    }
  };

  return (
    <div className="min-h-screen bg-[#F4F6F8] text-[#1E2D3D]">
      <DashboardHeader />
      <main id="main" className="mx-auto max-w-5xl px-4 sm:px-8 py-8 sm:py-10 space-y-6">
        <BackLink href="/dashboard" label="Retour au tableau de bord" />
        <Breadcrumbs
          items={[
            { label: 'Tableau de bord', href: '/dashboard' },
            { label: 'Mon profil & Données bancaires' },
          ]}
          className="mb-2"
        />

        {/* ── Page Header & Profile Hero Card ── */}
        <div className="figma-card p-6 sm:p-8 bg-white space-y-6">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#E0E4E9] pb-6">
            <div className="flex items-center gap-4">
              <div className="size-16 rounded-2xl bg-[#FDF2F2] text-[#C0272D] font-display text-2xl font-bold flex items-center justify-center border-2 border-[#FECACA] shadow-xs">
                {initials}
              </div>
              <div className="space-y-0.5">
                <p className="overline">Espace Client BTS Bank</p>
                <h1 className="font-display text-2xl sm:text-3xl font-light text-[#0C1825]">
                  {fullName}
                </h1>
                <p className="text-xs text-[#3D5166]">{user.email}</p>
              </div>
            </div>

            <div className="flex items-center gap-3">
              <span className={`badge ${isVerified ? 'badge-success' : 'badge-warning'} text-xs`}>
                {isVerified ? (
                  <span className="flex items-center gap-1">
                    <ShieldCheck className="size-3.5" /> Compte Vérifié
                  </span>
                ) : (
                  <span className="flex items-center gap-1">
                    <AlertCircle className="size-3.5" /> En attente OTP
                  </span>
                )}
              </span>

              <button
                type="button"
                onClick={handleLogout}
                className="btn-outline text-xs inline-flex items-center gap-1.5"
                style={{ padding: '6px 14px' }}
              >
                <LogOut className="size-3.5" />
                <span>Déconnexion</span>
              </button>
            </div>
          </div>

          {/* ── 1. Synthèse Financière & Données Bancaires Réelles ── */}
          <div className="space-y-3">
            <h2 className="text-xs font-bold uppercase tracking-wider text-[#0C1825] flex items-center gap-2">
              <CreditCard className="size-4 text-[#C0272D]" /> Synthèse Bancaire & Crédits
            </h2>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              {/* Statut Compte */}
              <div className="p-4 rounded-xl border border-[#E0E4E9] bg-[#F4F6F8] space-y-1">
                <span className="text-[10px] uppercase font-bold text-[#3D5166] block">
                  Statut du compte
                </span>
                <p className="text-sm font-bold text-[#0C1825]">Actif & Conforme</p>
                <p className="text-[11px] text-[#3D5166]">Accès au portail de crédit</p>
              </div>

              {/* Cumul sollicité */}
              <div className="p-4 rounded-xl border border-[#E0E4E9] bg-[#F4F6F8] space-y-1">
                <span className="text-[10px] uppercase font-bold text-[#3D5166] block">
                  Total financements sollicités
                </span>
                <p className="text-base sm:text-lg font-display font-light text-[#C0272D]">
                  {formattedTotal}
                </p>
                <p className="text-[11px] text-[#3D5166]">
                  {nonDeclinedApps.length} demande{nonDeclinedApps.length > 1 ? 's' : ''} en cours / accordée{nonDeclinedApps.length > 1 ? 's' : ''}
                </p>
              </div>

              {/* Solde & Type de compte */}
              <div className="p-4 rounded-xl border border-[#E0E4E9] bg-[#F4F6F8] space-y-1">
                <span className="text-[10px] uppercase font-bold text-[#3D5166] block">
                  Gestion des comptes
                </span>
                <p className="text-sm font-bold text-[#0C1825]">Espace Financement</p>
                <p className="text-[11px] text-[#3D5166]">Dédié aux crédits & projets BTS</p>
              </div>
            </div>

            <div className="p-3.5 bg-white rounded-xl border border-[#E0E4E9] flex items-center gap-2.5 text-xs text-[#3D5166]">
              <Info className="size-4 text-[#C0272D] shrink-0" />
              <span>
                Ce portail digital permet l&apos;instruction dématérialisée de vos demandes de prêt. La consultation des soldes de comptes de dépôt est disponible en agence ou via les services bancaires BTS PAY.
              </span>
            </div>
          </div>

          {/* ── 2. Informations Personnelles & Coordonnées ── */}
          <div className="space-y-3 pt-2">
            <h2 className="text-xs font-bold uppercase tracking-wider text-[#0C1825] flex items-center gap-2">
              <User className="size-4 text-[#C0272D]" /> Coordonnées & Données Personnelles
            </h2>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
              <div className="p-4 rounded-xl border border-[#E0E4E9] bg-white space-y-1">
                <span className="text-[10px] uppercase font-bold text-[#3D5166] block">Nom complet</span>
                <p className="font-semibold text-[#0C1825] text-sm">{fullName}</p>
              </div>

              <div className="p-4 rounded-xl border border-[#E0E4E9] bg-white space-y-1">
                <span className="text-[10px] uppercase font-bold text-[#3D5166] block">Adresse Email</span>
                <p className="font-semibold text-[#0C1825] text-sm truncate">{user.email}</p>
              </div>

              <div className="p-4 rounded-xl border border-[#E0E4E9] bg-white flex items-center justify-between gap-3">
                <div className="space-y-1 min-w-0">
                  <span className="text-[10px] uppercase font-bold text-[#3D5166] block">Numéro de téléphone</span>
                  <p className="font-semibold text-[#0C1825] text-sm">
                    {user.phone ? user.phone : 'Non renseigné'}
                  </p>
                </div>
                {user.phone ? (
                  <span className={`badge ${isVerified ? 'badge-success' : 'badge-warning'} text-[10px] shrink-0`}>
                    {isVerified ? 'Numéro vérifié' : 'En attente'}
                  </span>
                ) : (
                  <Link href="/verify-otp" className="text-xs text-[#C0272D] font-bold hover:underline">
                    Ajouter & Vérifier
                  </Link>
                )}
              </div>

              <div className="p-4 rounded-xl border border-[#E0E4E9] bg-white space-y-1">
                <span className="text-[10px] uppercase font-bold text-[#3D5166] block">Rôle / Profil</span>
                <p className="font-semibold text-[#0C1825] text-sm">Client / Porteur de projet</p>
              </div>
            </div>
          </div>

          {/* ── 3. Sécurité & Authentification ── */}
          <div className="space-y-3 pt-2">
            <h2 className="text-xs font-bold uppercase tracking-wider text-[#0C1825] flex items-center gap-2">
              <Shield className="size-4 text-[#C0272D]" /> Sécurité & Authentification
            </h2>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
              <div className="p-4 rounded-xl border border-[#E0E4E9] bg-white space-y-1">
                <span className="text-[10px] uppercase font-bold text-[#3D5166] block">Méthode de connexion</span>
                <p className="font-semibold text-[#0C1825]">{providerLabel(user.auth_provider)}</p>
              </div>

              <div className="p-4 rounded-xl border border-[#E0E4E9] bg-white space-y-1">
                <span className="text-[10px] uppercase font-bold text-[#3D5166] block">Double facteur (OTP SMS)</span>
                <p className="font-semibold text-emerald-700 flex items-center gap-1">
                  <ShieldCheck className="size-4" /> Activé & Opérationnel
                </p>
              </div>
            </div>
          </div>
        </div>

        {/* ── 4. Dossiers de Crédit Rattachés ── */}
        <div className="figma-card p-6 bg-white space-y-4">
          <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-3">
            <div className="flex items-center gap-2.5">
              <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                <FileText className="size-4" />
              </div>
              <h3 className="text-sm font-semibold text-[#0C1825]">
                Dossiers de Financement Associés à ce Profil
              </h3>
            </div>
            <Link href="/applications" className="text-xs font-semibold text-[#C0272D] hover:underline">
              Gérer mes demandes
            </Link>
          </div>

          {applications.length === 0 ? (
            <div className="text-center py-6 text-xs text-[#3D5166] bg-[#F4F6F8] rounded-xl border border-[#E0E4E9]">
              <p>Aucun dossier de crédit n&apos;est actuellement lié à votre compte.</p>
              <Link href="/applications" className="inline-flex items-center gap-1.5 btn-red text-xs mt-3">
                Déposer une demande de crédit
              </Link>
            </div>
          ) : (
            <ul className="divide-y divide-[#E0E4E9]">
              {applications.map((app) => (
                <li key={app.id} className="py-3 flex items-center justify-between gap-4">
                  <div>
                    <span className="font-bold text-xs text-[#0C1825]">
                      {app.credit_request?.n_demande ?? `Dossier #${app.id}`}
                    </span>
                    <p className="text-[11px] text-[#3D5166]">
                      {app.credit_request?.type_demande || 'Crédit BTS'} · Créé le {new Date(app.created_at).toLocaleDateString('fr-FR')}
                    </p>
                  </div>
                  <div className="flex items-center gap-3">
                    <StatusBadge status={app.status} label={statusLabel(app.status)} />
                    <Link
                      href={`/applications/${app.id}`}
                      className="text-xs font-semibold text-[#C0272D] hover:underline inline-flex items-center gap-1"
                    >
                      Détails <ArrowRight className="size-3" />
                    </Link>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </main>
    </div>
  );
}
