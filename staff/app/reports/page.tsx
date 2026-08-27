'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { MessageSquare } from 'lucide-react';
import { StaffHeader } from '@/components/staff-header';
import { ErrorAlert } from '@/components/error-alert';
import { listStaffReports } from '@/lib/api/reports';
import type { StaffApplicationDto } from '@/lib/api/staff';
import { ApiError } from '@/lib/api/client';
import { getStaffToken, getStaffRole } from '@/lib/auth/staff-token';
import { InlineLoading } from '@/components/page-loading';

export default function StaffReportsPage() {
  const router = useRouter();
  const [applications, setApplications] = useState<StaffApplicationDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const role = getStaffRole() ?? 'staff';

  useEffect(() => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    listStaffReports()
      .then(({ applications }) => setApplications(applications))
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Impossible de charger les signalements.'))
      .finally(() => setLoading(false));
  }, [router]);

  return (
    <div className="staff-page text-[#1E2D3D]">
      <StaffHeader role={role} />
      <main id="main" className="staff-main space-y-6">
        <section className="staff-page-hero">
          <p className="overline">Canal de Messagerie & Assistance</p>
          <h1 className="font-display text-3xl font-light text-[#0C1825]">
            Discussions & Signalements Clients
          </h1>
          <p className="text-xs text-[#3D5166] mt-1">
            Dossiers nécessitant un contact direct avec l&apos;agence (dossiers annulés ou créneaux de rendez-vous à convenir).
          </p>
        </section>

        <ErrorAlert message={error} />

        {loading ? (
          <div className="figma-card p-12 bg-white text-center">
            <InlineLoading />
          </div>
        ) : !error && applications.length === 0 ? (
          <div className="figma-card p-12 bg-white text-center space-y-3">
            <MessageSquare className="size-10 text-gray-400 mx-auto" />
            <p className="font-semibold text-[#0C1825] text-sm">Aucun dossier ne requiert d&apos;échange en ce moment</p>
            <p className="text-xs text-[#3D5166] max-w-sm mx-auto">
              Toutes les discussions et ajustements de rendez-vous sont à jour.
            </p>
          </div>
        ) : (
          <div className="staff-table-shell">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs border-collapse">
                <thead>
                  <tr className="border-b border-[#E0E4E9] bg-[#F4F6F8] text-[#3D5166] uppercase text-[10px] font-bold tracking-wider">
                    <th className="py-3.5 px-4">N° Demande</th>
                    <th className="py-3.5 px-4">Demandeur</th>
                    <th className="py-3.5 px-4">Type de Dossier</th>
                    <th className="py-3.5 px-4">Date de Création</th>
                    <th className="py-3.5 px-4">Statut</th>
                    <th className="py-3.5 px-4 text-right">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#E0E4E9]">
                  {applications.map((app) => (
                    <tr
                      key={app.id}
                      onClick={() => router.push(`/reports/${app.id}`)}
                      className="hover:bg-[#FDF2F2]/40 transition-colors cursor-pointer group"
                    >
                      <td className="py-4 px-4 font-mono font-bold text-[#C0272D]">
                        {app.credit_request?.n_demande ?? `#${app.id}`}
                      </td>
                      <td className="py-4 px-4 font-semibold text-[#0C1825]">
                        {app.applicant?.name ?? '—'}
                      </td>
                      <td className="py-4 px-4 text-[#3D5166]">
                        {app.credit_request?.type_demande || 'Crédit BTS'}
                      </td>
                      <td className="py-4 px-4 text-[#3D5166]">
                        {new Date(app.created_at).toLocaleDateString('fr-FR')}
                      </td>
                      <td className="py-4 px-4">
                        <span className={`badge text-[10px] ${
                          app.status === 'APPOINTMENT_CONFIRMED'
                            ? 'badge-success'
                            : app.status === 'APPOINTMENT_LOCKED'
                            ? 'badge-warning'
                            : 'badge-danger'
                        }`}>
                          {app.status === 'APPOINTMENT_CONFIRMED'
                            ? 'RDV Confirmé'
                            : app.status === 'APPOINTMENT_LOCKED'
                            ? 'RDV à planifier'
                            : 'Annulé / Échange'}
                        </span>
                      </td>
                      <td className="py-4 px-4 text-right">
                        <Link
                          href={`/reports/${app.id}`}
                          onClick={(event) => event.stopPropagation()}
                          aria-label={`Ouvrir la discussion du dossier ${app.credit_request?.n_demande ?? app.id}`}
                          className="btn-red px-2.5 py-1 text-[11px]"
                        >
                          <MessageSquare className="size-3" />
                          <span>Ouvrir discussion</span>
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </main>
    </div>
  );
}
