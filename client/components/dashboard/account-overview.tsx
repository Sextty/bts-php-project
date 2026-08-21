'use client';

import Link from 'next/link';
import {
  FileText,
  TrendingUp,
  ShieldCheck,
  AlertCircle,
  ArrowRight,
  Info,
} from 'lucide-react';
import type { CreditApplicationDto } from '@/lib/api/credit-applications';

function isTerminal(status: string): boolean {
  return ['APPROVED', 'STAFF_APPROVED', 'REJECTED', 'STAFF_REJECTED', 'CANCELLED'].includes(status);
}

export function AccountOverview({
  applications = [],
  phoneVerified,
}: {
  applications?: CreditApplicationDto[];
  phoneVerified: boolean;
}) {
  const safeApps = Array.isArray(applications) ? applications : [];
  const activeApps = safeApps.filter((a) => !isTerminal(a.status));
  const DECLINED_STATUSES = new Set(['REJECTED', 'STAFF_REJECTED', 'CANCELLED']);
  const nonDeclinedApps = safeApps.filter((a) => !DECLINED_STATUSES.has(a.status));
  const totalAmount = nonDeclinedApps.reduce((sum, a) => {
    const amt = Number(a.credit_request?.montant_global_sollicite);
    return sum + (isNaN(amt) ? 0 : amt);
  }, 0);

  const formattedTotal =
    totalAmount > 0
      ? new Intl.NumberFormat('fr-TN', { maximumFractionDigits: 0 }).format(totalAmount) + ' TND'
      : '0 TND';

  return (
    <div className="mb-8">
      <div className="flex items-center justify-between mb-4">
        <div>
          <p className="overline mb-0.5">Synthèse Réelle</p>
          <h2 className="font-display text-xl font-light text-[#0C1825]">
            Aperçu de votre activité
          </h2>
        </div>
        <span className="text-[11px] font-mono text-[#3D5166] bg-[#F4F6F8] px-2.5 py-1 rounded border border-[#E0E4E9]">
          Données synchronisées en temps réel
        </span>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {/* 1. Demandes en cours */}
        <div className="figma-card p-5 bg-white space-y-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold uppercase tracking-wider text-[#3D5166]">
              Demandes de crédit
            </span>
            <div className="size-8 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
              <FileText className="size-4" />
            </div>
          </div>
          <div>
            <div className="flex items-baseline gap-2">
              <span className="font-display text-3xl font-light text-[#0C1825]">
                {activeApps.length}
              </span>
              <span className="text-xs text-[#3D5166]">
                active{activeApps.length > 1 ? 's' : ''} ({safeApps.length} au total)
              </span>
            </div>
          </div>
          <div className="pt-2 border-t border-[#E0E4E9] flex items-center justify-between text-xs">
            <Link
              href="/applications"
              className="text-[#C0272D] font-medium hover:underline inline-flex items-center gap-1"
            >
              Consulter mes dossiers <ArrowRight className="size-3" />
            </Link>
          </div>
        </div>

        {/* 2. Montant total sollicité */}
        <div className="figma-card p-5 bg-white space-y-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold uppercase tracking-wider text-[#3D5166]">
              Total sollicité
            </span>
            <div className="size-8 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
              <TrendingUp className="size-4" />
            </div>
          </div>
          <div>
            <div className="flex items-baseline gap-2">
              <span className="font-display text-2xl sm:text-3xl font-light text-[#0C1825]">
                {formattedTotal}
              </span>
            </div>
          </div>
          <div className="pt-2 border-t border-[#E0E4E9] flex items-center gap-1.5 text-xs text-[#3D5166]">
            <Info className="size-3 text-[#3D5166] shrink-0" />
            <span>Cumul de vos demandes de financement</span>
          </div>
        </div>

        {/* 3. Statut du profil & Sécurité */}
        <div className="figma-card p-5 bg-white space-y-3 sm:col-span-2 lg:col-span-1">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold uppercase tracking-wider text-[#3D5166]">
              Sécurité & Identité
            </span>
            <div className={`size-8 rounded-lg flex items-center justify-center ${
              phoneVerified ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600'
            }`}>
              {phoneVerified ? <ShieldCheck className="size-4" /> : <AlertCircle className="size-4" />}
            </div>
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span className="font-display text-xl font-light text-[#0C1825]">
                {phoneVerified ? 'Identité Validée' : 'Vérification Requise'}
              </span>
              <span className={`badge ${phoneVerified ? 'badge-success' : 'badge-warning'} text-[10px]`}>
                {phoneVerified ? 'Vérifié' : 'En attente'}
              </span>
            </div>
          </div>
          <div className="pt-2 border-t border-[#E0E4E9] flex items-center justify-between text-xs">
            {phoneVerified ? (
              <span className="text-emerald-700 font-medium inline-flex items-center gap-1">
                <ShieldCheck className="size-3" /> Accès complet aux services
              </span>
            ) : (
              <Link
                href="/profile"
                className="text-amber-700 font-semibold hover:underline inline-flex items-center gap-1"
              >
                Gérer mon profil & sécurité <ArrowRight className="size-3" />
              </Link>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
