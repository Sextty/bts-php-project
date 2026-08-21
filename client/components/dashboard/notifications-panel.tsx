'use client';

import { Bell, CheckCheck, ExternalLink } from 'lucide-react';
import Link from 'next/link';
import type { NotificationDto } from '@/lib/api/notifications';

function extractAppId(data: Record<string, unknown> | null): number | null {
  if (!data) return null;
  const id = data.application_id ?? data.credit_application_id;
  if (typeof id === 'number') return id;
  return null;
}

export function NotificationsPanel({
  notifications = [],
  onMarkRead,
}: {
  notifications?: NotificationDto[];
  onMarkRead: (id: number) => void;
}) {
  const safeNotifs = Array.isArray(notifications) ? notifications : [];
  const recent = safeNotifs.slice(0, 6);
  const unreadCount = safeNotifs.filter((n) => !n.read_at).length;

  return (
    <div className="figma-card p-6 bg-white space-y-4" id="notifications">
      <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-4">
        <div className="flex items-center gap-2.5">
          <div className="size-8 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
            <Bell className="size-4" />
          </div>
          <div className="flex items-center gap-2">
            <h3 className="text-base font-semibold text-[#0C1825]">
              Notifications
            </h3>
            {unreadCount > 0 && (
              <span className="flex size-5 items-center justify-center rounded-full bg-[#C0272D] text-[10px] font-bold text-white">
                {unreadCount > 9 ? '9+' : unreadCount}
              </span>
            )}
          </div>
        </div>

        {unreadCount > 0 && (
          <button
            type="button"
            className="text-xs font-semibold text-[#3D5166] hover:text-[#C0272D] inline-flex items-center gap-1 transition-colors"
            onClick={() => onMarkRead(-1)}
          >
            <CheckCheck className="size-3.5" />
            Tout lire
          </button>
        )}
      </div>

      {recent.length === 0 ? (
        <div className="py-8 text-center space-y-2 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] p-6">
          <Bell className="mx-auto size-7 text-gray-400" />
          <p className="text-sm font-semibold text-[#0C1825]">Aucune notification</p>
          <p className="text-xs text-[#3D5166]">
            Les mises à jour de vos dossiers apparaîtront ici.
          </p>
        </div>
      ) : (
        <ul className="space-y-2.5">
          {recent.map((notif) => {
            const isUnread = !notif.read_at;
            const appId = extractAppId(notif.data);

            return (
              <li
                key={notif.id}
                className={`relative rounded-xl border p-3.5 transition-colors ${
                  isUnread
                    ? 'border-[#FECACA] bg-[#FDF2F2]/60'
                    : 'border-[#E0E4E9] bg-white hover:bg-[#F4F6F8]'
                }`}
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0 space-y-0.5 flex-1">
                    <p
                      className={`text-xs leading-snug ${
                        isUnread ? 'font-bold text-[#0C1825]' : 'font-medium text-[#3D5166]'
                      }`}
                    >
                      {notif.title}
                    </p>
                    <p className="line-clamp-2 text-[11px] text-[#3D5166]">
                      {notif.body}
                    </p>
                    <div className="mt-1 flex items-center gap-3 text-[10px] text-gray-400">
                      <span>
                        {new Date(notif.created_at).toLocaleDateString('fr-FR', {
                          day: 'numeric',
                          month: 'short',
                          hour: '2-digit',
                          minute: '2-digit',
                        })}
                      </span>
                      {appId && (
                        <Link
                          href={`/applications/${appId}`}
                          className="inline-flex items-center gap-0.5 text-[#C0272D] font-semibold hover:underline"
                        >
                          Dossier #{appId}
                          <ExternalLink className="size-2.5" />
                        </Link>
                      )}
                    </div>
                  </div>

                  {isUnread && (
                    <button
                      type="button"
                      className="text-[10px] font-semibold text-[#C0272D] bg-white px-2 py-0.5 rounded border border-[#FECACA] hover:bg-[#FDF2F2] shrink-0"
                      onClick={() => onMarkRead(notif.id)}
                    >
                      Lu
                    </button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
