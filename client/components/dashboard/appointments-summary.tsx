'use client';

import Link from 'next/link';
import {
  Calendar,
  MapPin,
  Clock,
  ArrowRight,
  CalendarCheck,
  CalendarX2,
} from 'lucide-react';
import type { CreditApplicationDto } from '@/lib/api/credit-applications';

interface AppointmentItem {
  applicationId: number;
  applicationNumber: string;
  scheduledDate: string;
  scheduledTime: string;
  status: string;
  branchName?: string;
  branchAddress?: string;
  isAutoScheduled: boolean;
  remainingReschedules: number;
}

function extractAppointments(apps: CreditApplicationDto[]): AppointmentItem[] {
  const items: AppointmentItem[] = [];
  const safeApps = Array.isArray(apps) ? apps : [];
  for (const app of safeApps) {
    const apt = app.latest_appointment;
    if (!apt) continue;
    if (apt.status === 'cancelled' || apt.status === 'rejected') continue;
    items.push({
      applicationId: app.id,
      applicationNumber: app.credit_request?.n_demande ?? `Demande #${app.id}`,
      scheduledDate: apt.scheduled_date,
      scheduledTime: apt.scheduled_time,
      status: apt.status,
      branchName: apt.branch?.name,
      branchAddress: apt.branch?.address,
      isAutoScheduled: apt.is_auto_scheduled_future,
      remainingReschedules: apt.remaining_reschedules,
    });
  }
  return items;
}

function statusConfig(status: string) {
  if (status === 'accepted') {
    return { label: 'Confirmé', badgeClass: 'badge-success', icon: CalendarCheck };
  }
  return { label: 'Demande acceptée', badgeClass: 'badge-success', icon: Calendar };
}

export function AppointmentsSummary({
  applications = [],
}: {
  applications?: CreditApplicationDto[];
}) {
  const appointments = extractAppointments(applications);

  return (
    <div className="figma-card p-6 bg-white space-y-4" id="appointments">
        <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-4">
          <div className="flex items-center gap-2.5">
            <div className="size-8 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
              <Calendar className="size-4" />
            </div>
            <div>
              <h3 className="text-base font-semibold text-[#0C1825]">
                Rendez-vous en agence
              </h3>
              <p className="text-xs text-[#3D5166]">
                Entretiens et signatures de contrat planifiés
              </p>
            </div>
          </div>
          <Link
            href="/appointments"
            className="text-xs font-semibold text-[#C0272D] hover:underline hidden sm:inline-block"
          >
            Gérer mes rendez-vous
          </Link>
        </div>

      {appointments.length === 0 ? (
        <div className="py-8 text-center space-y-2 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] p-6">
          <CalendarX2 className="mx-auto size-8 text-gray-400" />
          <p className="text-sm font-semibold text-[#0C1825]">Aucun rendez-vous à venir</p>
          <p className="text-xs text-[#3D5166] max-w-sm mx-auto">
            Un rendez-vous en agence vous sera proposé automatiquement une fois l&apos;étude de votre dossier validée.
          </p>
        </div>
      ) : (
        <ul className="space-y-3">
          {appointments.map((apt) => {
            const cfg = statusConfig(apt.status);
            const StatusIcon = cfg.icon;
            return (
              <li key={`${apt.applicationId}-${apt.scheduledDate}`}>
                <Link
                  href={`/applications/${apt.applicationId}/appointment`}
                  className="block rounded-xl border border-[#E0E4E9] bg-white p-4 transition-all hover:border-[#C0272D] hover:shadow-sm group"
                >
                  <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                    <div className="min-w-0 space-y-1">
                      <p className="text-sm font-bold text-[#0C1825] group-hover:text-[#C0272D] transition-colors">
                        {apt.branchName ?? 'Agence Régionale BTS'}
                      </p>
                      {apt.branchAddress && (
                        <p className="flex items-center gap-1 text-xs text-[#3D5166]">
                          <MapPin className="size-3 text-[#C0272D] shrink-0" />
                          {apt.branchAddress}
                        </p>
                      )}
                      <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[#3D5166]">
                        <span className="flex items-center gap-1 font-medium text-[#0C1825]">
                          <Calendar className="size-3 text-[#C0272D]" />
                          {new Date(apt.scheduledDate).toLocaleDateString('fr-FR', {
                            weekday: 'long',
                            day: 'numeric',
                            month: 'long',
                            year: 'numeric',
                          })}
                        </span>
                        <span className="flex items-center gap-1">
                          <Clock className="size-3 text-gray-400" />
                          {apt.scheduledTime.slice(0, 5)}
                        </span>
                      </div>
                      <p className="text-[10px] text-[#3D5166] pt-0.5">
                        Rattaché à : <span className="font-mono font-medium">{apt.applicationNumber}</span>
                      </p>
                      {apt.status === 'proposed' && (
                        <p className="text-[11px] font-semibold text-[#0C1825]">
                          {apt.remainingReschedules} changement{apt.remainingReschedules === 1 ? '' : 's'} restant{apt.remainingReschedules === 1 ? '' : 's'}
                        </p>
                      )}
                    </div>

                    <div className="flex shrink-0 items-center sm:flex-col sm:items-end gap-2">
                      <span className={`badge ${cfg.badgeClass} text-[10px]`}>
                        <StatusIcon className="mr-1 size-3" />
                        {cfg.label}
                      </span>
                      {apt.isAutoScheduled && (
                        <span className="text-[10px] font-medium bg-[#F4F6F8] px-2 py-0.5 rounded text-[#3D5166]">
                          Automatique
                        </span>
                      )}
                      <ArrowRight className="size-4 text-gray-400 group-hover:text-[#C0272D] group-hover:translate-x-0.5 transition-all" />
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
