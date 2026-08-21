'use client';

import Link from 'next/link';
import {
  FileText,
  Calendar,
  FileUp,
  MessageSquare,
  User,
  ArrowRight,
  PlusCircle,
  Shield,
} from 'lucide-react';

interface QuickAction {
  label: string;
  description: string;
  icon: React.ComponentType<{ className?: string }>;
  href?: string;
  available: boolean;
  badge?: string;
  highlight?: boolean;
}

export function QuickActions({
  hasLockedApp,
  lockedAppId,
}: {
  hasLockedApp: boolean;
  lockedAppId?: number;
}) {
  const actions: QuickAction[] = [
    {
      label: 'Nouvelle demande',
      description: 'Déposer un dossier de crédit',
      icon: PlusCircle,
      href: '/applications',
      available: true,
      highlight: true,
    },
    {
      label: 'Mes demandes',
      description: 'Consulter l\u2019état d\u2019avancement',
      icon: FileText,
      href: '/applications',
      available: true,
    },
    {
      label: 'Rendez-vous',
      description: 'Calendrier & choix d\u2019agence',
      icon: Calendar,
      href: '/appointments',
      available: true,
    },
    {
      label: 'Pièces & Documents',
      description: 'Justificatifs et devis',
      icon: FileUp,
      href: '/applications',
      available: true,
    },
    {
      label: 'Discussion Conseiller',
      description: 'Messagerie agence',
      icon: MessageSquare,
      href: hasLockedApp && lockedAppId ? `/applications/${lockedAppId}/report` : '#messages',
      available: hasLockedApp,
      badge: hasLockedApp ? 'Actif' : 'Sur RDV',
    },
    {
      label: 'Mon profil & Données',
      description: 'Solde, coordonnées & sécurité',
      icon: User,
      href: '/profile',
      available: true,
    },
  ];

  return (
    <div className="mb-8">
      <div className="flex items-center justify-between mb-4">
        <div>
          <p className="overline mb-0.5">Navigation Directe</p>
          <h2 className="font-display text-xl font-light text-[#0C1825]">
            Actions rapides
          </h2>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        {actions.map((action) => {
          const Icon = action.icon;
          const isLink = !!(action.href && action.available);

          return (
            <div key={action.label} className="group">
              {isLink ? (
                <Link
                  href={action.href!}
                  className={`block figma-card p-4 transition-all hover:border-[#C0272D] hover:shadow-md ${
                    action.highlight ? 'bg-[#FDF2F2] border-[#FECACA]' : 'bg-white'
                  }`}
                >
                  <ActionContent action={action} Icon={Icon} isLink={isLink} />
                </Link>
              ) : (
                <div className="figma-card p-4 bg-white opacity-70 cursor-not-allowed">
                  <ActionContent action={action} Icon={Icon} isLink={false} />
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

function ActionContent({
  action,
  Icon,
  isLink,
}: {
  action: QuickAction;
  Icon: React.ComponentType<{ className?: string }>;
  isLink: boolean;
}) {
  return (
    <div className="flex items-start gap-3.5">
      <div
        className={`size-10 shrink-0 rounded-lg flex items-center justify-center transition-colors ${
          action.highlight
            ? 'bg-[#C0272D] text-white'
            : isLink
            ? 'bg-[#FDF2F2] text-[#C0272D] group-hover:bg-[#C0272D] group-hover:text-white'
            : 'bg-[#F4F6F8] text-[#3D5166]'
        }`}
      >
        <Icon className="size-5" />
      </div>
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <p className="text-sm font-semibold text-[#0C1825] group-hover:text-[#C0272D] transition-colors">
            {action.label}
          </p>
          {action.badge && (
            <span className="text-[9px] font-bold bg-[#F4F6F8] border border-[#E0E4E9] px-1.5 py-0.5 rounded text-[#3D5166]">
              {action.badge}
            </span>
          )}
        </div>
        <p className="text-xs text-[#3D5166] mt-0.5">{action.description}</p>
      </div>
      {isLink && (
        <ArrowRight className="size-4 shrink-0 text-gray-400 group-hover:text-[#C0272D] group-hover:translate-x-0.5 transition-all mt-1" />
      )}
    </div>
  );
}
