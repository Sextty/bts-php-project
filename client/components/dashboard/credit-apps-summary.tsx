'use client';

import Link from 'next/link';
import { FileText, ArrowRight, Building2, Calendar, PlusCircle } from 'lucide-react';
import { StatusBadge } from '@/components/status-badge';
import { statusLabel, statusPhase } from '@/lib/status-labels';
import type { CreditApplicationDto } from '@/lib/api/credit-applications';

function progressPercent(status: string): number {
  const phase = statusPhase(status);
  const map: Record<string, number> = {
    draft: 20,
    validation: 40,
    submitted: 60,
    review: 80,
    appointment: 90,
    terminal: 100,
  };
  return map[phase] ?? 10;
}

function isTerminal(status: string): boolean {
  return ['APPROVED', 'STAFF_APPROVED', 'REJECTED', 'STAFF_REJECTED', 'CANCELLED'].includes(status);
}

export function CreditAppsSummary({
  applications = [],
}: {
  applications?: CreditApplicationDto[];
}) {
  const safeApps = Array.isArray(applications) ? applications : [];
  const recent = safeApps.slice(-5).reverse();

  return (
    <div className="figma-card p-6 bg-white space-y-4">
      <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-4">
        <div className="flex items-center gap-2.5">
          <div className="size-8 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
            <FileText className="size-4" />
          </div>
          <div>
            <h3 className="text-base font-semibold text-[#0C1825]">
              Demandes de crédit récentes
            </h3>
            <p className="text-xs text-[#3D5166]">
              Suivez l&apos;état d&apos;avancement de vos dossiers
            </p>
          </div>
        </div>

        {safeApps.length > 0 && (
          <Link
            href="/applications"
            className="text-xs font-semibold text-[#C0272D] hover:underline inline-flex items-center gap-1"
          >
            Voir tout ({safeApps.length}) <ArrowRight className="size-3" />
          </Link>
        )}
      </div>

      {recent.length === 0 ? (
        <div className="py-8 text-center space-y-3 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] p-6">
          <div className="size-12 rounded-full bg-[#E0E4E9] text-[#3D5166] mx-auto flex items-center justify-center">
            <FileText className="size-6 opacity-60" />
          </div>
          <div className="space-y-1">
            <p className="text-sm font-semibold text-[#0C1825]">Aucune demande en cours</p>
            <p className="text-xs text-[#3D5166] max-w-sm mx-auto">
              Vous n&apos;avez pas encore déposé de demande de crédit. Démarrez votre dossier dès aujourd&apos;hui.
            </p>
          </div>
          <Link
            href="/applications"
            className="inline-flex items-center gap-2 btn-red text-xs mt-2"
          >
            <PlusCircle className="size-3.5" />
            Déposer une nouvelle demande
          </Link>
        </div>
      ) : (
        <ul className="space-y-3">
          {recent.map((app) => {
            const pct = progressPercent(app.status);
            const amountVal = app.credit_request?.montant_global_sollicite
              ? Number(app.credit_request.montant_global_sollicite)
              : 0;
            const formattedAmt =
              amountVal > 0
                ? new Intl.NumberFormat('fr-TN', { maximumFractionDigits: 0 }).format(amountVal) +
                  ' ' +
                  (app.credit_request?.code_devise ?? 'TND')
                : 'Montant non défini';

            return (
              <li key={app.id}>
                <Link
                  href={`/applications/${app.id}`}
                  className="block rounded-xl border border-[#E0E4E9] bg-white p-4 transition-all hover:border-[#C0272D] hover:shadow-sm group"
                >
                  <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3 mb-3">
                    <div className="min-w-0 space-y-1">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="text-sm font-bold text-[#0C1825] group-hover:text-[#C0272D] transition-colors">
                          {app.credit_request?.n_demande ?? `Dossier #${app.id}`}
                        </span>
                        {app.credit_request?.type_demande && (
                          <span className="text-[10px] font-medium text-[#3D5166] bg-[#F4F6F8] px-2 py-0.5 rounded">
                            {app.credit_request.type_demande}
                          </span>
                        )}
                      </div>

                      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[#3D5166]">
                        <span className="font-semibold text-[#0C1825]">{formattedAmt}</span>
                        {app.branch?.name && (
                          <span className="flex items-center gap-1">
                            <Building2 className="size-3 text-[#C0272D]" />
                            {app.branch.name}
                          </span>
                        )}
                        <span className="flex items-center gap-1">
                          <Calendar className="size-3 text-gray-400" />
                          {new Date(app.created_at).toLocaleDateString('fr-FR')}
                        </span>
                      </div>
                    </div>

                    <div className="flex shrink-0 items-center gap-2">
                      <StatusBadge
                        status={app.status}
                        label={statusLabel(app.status)}
                      />
                      {!isTerminal(app.status) && (
                        <ArrowRight className="size-4 text-gray-400 group-hover:text-[#C0272D] group-hover:translate-x-0.5 transition-all" />
                      )}
                    </div>
                  </div>

                  {/* Progress bar */}
                  <div className="space-y-1 pt-1">
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-[#F4F6F8]">
                      <div
                        className="h-full rounded-full bg-[#C0272D] transition-all duration-500"
                        style={{ width: `${pct}%` }}
                      />
                    </div>
                    <div className="flex items-center justify-between text-[10px] text-[#3D5166]">
                      <span>Progression du dossier</span>
                      <span className="font-mono font-bold text-[#0C1825]">{pct}%</span>
                    </div>
                  </div>
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
