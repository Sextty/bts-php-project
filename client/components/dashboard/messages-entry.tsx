'use client';

import Link from 'next/link';
import { MessageSquare, ArrowRight, MessageCircle } from 'lucide-react';
import type { CreditApplicationDto } from '@/lib/api/credit-applications';

export function MessagesEntry({
  applications = [],
}: {
  applications?: CreditApplicationDto[];
}) {
  const safeApps = Array.isArray(applications) ? applications : [];
  const chatApps = safeApps.filter(
    (a) =>
      a.status === 'APPOINTMENT_LOCKED' ||
      a.status === 'CANCELLED'
  );

  return (
    <div className="figma-card p-6 bg-white space-y-4" id="messages">
      <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-4">
        <div className="flex items-center gap-2.5">
          <div className="size-8 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
            <MessageSquare className="size-4" />
          </div>
          <div className="flex items-center gap-2">
            <h3 className="text-base font-semibold text-[#0C1825]">
              Discussion Conseiller
            </h3>
            {chatApps.length > 0 && (
              <span className="badge badge-neutral text-[10px]">
                {chatApps.length}
              </span>
            )}
          </div>
        </div>
      </div>

      {chatApps.length === 0 ? (
        <div className="py-8 text-center space-y-2 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] p-6">
          <MessageCircle className="mx-auto size-7 text-gray-400" />
          <p className="text-sm font-semibold text-[#0C1825]">Messagerie inactive</p>
          <p className="text-xs text-[#3D5166] max-w-xs mx-auto">
            Le canal de messagerie directe avec votre conseiller s&apos;activera automatiquement dès la validation d&apos;un rendez-vous.
          </p>
        </div>
      ) : (
        <div className="space-y-2.5">
          {chatApps.map((app) => (
            <Link
              key={app.id}
              href={`/applications/${app.id}/report`}
              className="flex items-center justify-between gap-3 rounded-xl border border-[#E0E4E9] bg-white p-3.5 transition-all hover:border-[#C0272D] hover:shadow-sm group"
            >
              <div className="min-w-0 space-y-0.5">
                <p className="text-xs font-bold text-[#0C1825] group-hover:text-[#C0272D] transition-colors truncate">
                  {app.credit_request?.n_demande ?? `Dossier #${app.id}`}
                </p>
                <p className="text-[11px] text-[#3D5166]">
                  Échanges avec l&apos;agence {app.branch?.name ?? 'BTS'}
                </p>
              </div>
              <span className="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-[#FDF2F2] px-3 py-1.5 text-xs font-semibold text-[#C0272D] transition-colors group-hover:bg-[#C0272D] group-hover:text-white">
                <MessageSquare className="size-3.5" />
                Ouvrir
                <ArrowRight className="size-3" />
              </span>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
