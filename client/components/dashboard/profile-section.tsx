'use client';

import {
  User,
  Mail,
  Phone,
  ShieldCheck,
  BadgeCheck,
  KeyRound,
  AlertCircle,
  ArrowRight,
} from 'lucide-react';
import Link from 'next/link';
import type { UserDto } from '@/lib/api/auth';

export function ProfileSection({ user }: { user: UserDto }) {
  const isVerified = !!user.phone_verified;

  const providerLabel = (p: string) => {
    switch (p) {
      case 'google':
        return 'Authentification Google';
      case 'password':
        return 'Email / Mot de passe sécurisé';
      default:
        return p;
    }
  };

  return (
    <div className="figma-card p-6 bg-white space-y-6 mt-8" id="profile">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#E0E4E9] pb-4">
        <div className="flex items-center gap-2.5">
          <div className="size-8 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
            <User className="size-4" />
          </div>
          <div>
            <h3 className="text-base font-semibold text-[#0C1825]">
              Mon profil utilisateur
            </h3>
            <p className="text-xs text-[#3D5166]">
              Coordonnées et paramètres d&apos;authentification
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <span className={`badge ${isVerified ? 'badge-success' : 'badge-warning'} text-xs`}>
            {isVerified ? (
              <span className="flex items-center gap-1">
                <ShieldCheck className="size-3.5" /> Compte Vérifié
              </span>
            ) : (
              <span className="flex items-center gap-1">
                <AlertCircle className="size-3.5" /> En attente de vérification
              </span>
            )}
          </span>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {/* Nom Complet */}
        <div className="p-3.5 rounded-xl border border-[#E0E4E9] bg-[#F4F6F8] flex items-center gap-3">
          <div className="size-9 rounded-lg bg-white border border-[#E0E4E9] text-[#0C1825] flex items-center justify-center shrink-0">
            <User className="size-4 text-[#C0272D]" />
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-[10px] font-bold uppercase tracking-wider text-[#3D5166]">
              Nom & Prénom
            </p>
            <p className="text-xs font-semibold text-[#0C1825] truncate">
              {user.first_name || user.last_name
                ? `${user.first_name ?? ''} ${user.last_name ?? ''}`.trim()
                : 'Non renseigné'}
            </p>
          </div>
        </div>

        {/* Email */}
        <div className="p-3.5 rounded-xl border border-[#E0E4E9] bg-[#F4F6F8] flex items-center gap-3">
          <div className="size-9 rounded-lg bg-white border border-[#E0E4E9] text-[#0C1825] flex items-center justify-center shrink-0">
            <Mail className="size-4 text-[#C0272D]" />
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-[10px] font-bold uppercase tracking-wider text-[#3D5166]">
              Adresse Email
            </p>
            <p className="text-xs font-semibold text-[#0C1825] truncate">
              {user.email}
            </p>
          </div>
        </div>

        {/* Téléphone */}
        <div className="p-3.5 rounded-xl border border-[#E0E4E9] bg-[#F4F6F8] flex items-center justify-between gap-3">
          <div className="flex items-center gap-3 min-w-0">
            <div className="size-9 rounded-lg bg-white border border-[#E0E4E9] text-[#0C1825] flex items-center justify-center shrink-0">
              <Phone className="size-4 text-[#C0272D]" />
            </div>
            <div className="min-w-0">
              <p className="text-[10px] font-bold uppercase tracking-wider text-[#3D5166]">
                Numéro de téléphone
              </p>
              <p className="text-xs font-semibold text-[#0C1825]">
                {user.phone ? user.phone : 'Non renseigné'}
              </p>
            </div>
          </div>

          {!isVerified && (
            <Link
              href="/verify-otp"
              className="text-[11px] font-semibold text-[#C0272D] hover:underline inline-flex items-center gap-1 shrink-0"
            >
              Vérifier <ArrowRight className="size-3" />
            </Link>
          )}
        </div>

        {/* Mode de connexion */}
        <div className="p-3.5 rounded-xl border border-[#E0E4E9] bg-[#F4F6F8] flex items-center gap-3">
          <div className="size-9 rounded-lg bg-white border border-[#E0E4E9] text-[#0C1825] flex items-center justify-center shrink-0">
            <KeyRound className="size-4 text-[#C0272D]" />
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-[10px] font-bold uppercase tracking-wider text-[#3D5166]">
              Sécurité du compte
            </p>
            <p className="text-xs font-semibold text-[#0C1825]">
              {providerLabel(user.auth_provider ?? 'password')}
            </p>
          </div>
        </div>
      </div>

      {isVerified ? (
        <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-2.5 text-xs text-emerald-800">
          <BadgeCheck className="size-4 text-emerald-600 shrink-0" />
          <span>
            Identité vérifiée par code SMS — Vous pouvez déposer et signer vos demandes de crédit en toute conformité.
          </span>
        </div>
      ) : (
        <div className="p-3 bg-amber-50 border border-amber-200 rounded-xl flex items-center justify-between gap-2.5 text-xs text-amber-900">
          <div className="flex items-center gap-2">
            <AlertCircle className="size-4 text-amber-600 shrink-0" />
            <span>Veuillez vérifier votre numéro de téléphone pour finaliser votre dossier.</span>
          </div>
          <Link
            href="/verify-otp"
            className="btn-red text-[11px] py-1 px-2.5 shrink-0"
          >
            Vérifier maintenant
          </Link>
        </div>
      )}
    </div>
  );
}
