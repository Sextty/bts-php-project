'use client';

import { useEffect, useState, useCallback } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { getToken, clearToken } from '@/lib/auth/token';
import { getCurrentUser, type UserDto } from '@/lib/api/auth';
import { listApplications, type CreditApplicationDto } from '@/lib/api/credit-applications';
import { getNotifications, markNotificationsRead, type NotificationDto } from '@/lib/api/notifications';
import { DashboardSkeleton } from '@/components/dashboard/skeleton';
import { AccountOverview } from '@/components/dashboard/account-overview';
import { QuickActions } from '@/components/dashboard/quick-actions';
import { CreditAppsSummary } from '@/components/dashboard/credit-apps-summary';
import { AppointmentsSummary } from '@/components/dashboard/appointments-summary';
import { NotificationsPanel } from '@/components/dashboard/notifications-panel';
import { MessagesEntry } from '@/components/dashboard/messages-entry';
import { DashboardHeader } from '@/components/dashboard-header';
import { PlusCircle } from 'lucide-react';

interface DashboardData {
  user: UserDto;
  applications: CreditApplicationDto[];
  notifications: NotificationDto[];
}

function formatFrenchDate() {
  return new Date().toLocaleDateString('fr-FR', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
}

export default function DashboardPage() {
  const router = useRouter();
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState<DashboardData | null>(null);

  useEffect(() => {
    const token = getToken();
    if (!token) {
      router.replace('/login');
      return;
    }

    let cancelled = false;

    async function load() {
      try {
        const [userResult, appsResult, notifsResult] = await Promise.allSettled([
          getCurrentUser(),
          listApplications(),
          getNotifications(),
        ]);

        if (cancelled) return;

        if (userResult.status === 'rejected') {
          clearToken();
          router.replace('/login');
          return;
        }

        const rawApps = appsResult.status === 'fulfilled' ? appsResult.value : undefined;
        const rawNotifs = notifsResult.status === 'fulfilled' ? notifsResult.value : undefined;

        const resolvedApps: CreditApplicationDto[] =
          Array.isArray(rawApps) ? rawApps as CreditApplicationDto[]
          : (rawApps && typeof rawApps === 'object' && Array.isArray((rawApps as Record<string, unknown>).applications))
            ? (rawApps as unknown as { applications: CreditApplicationDto[] }).applications
            : [];

        const resolvedNotifs: NotificationDto[] =
          Array.isArray(rawNotifs) ? rawNotifs
          : (rawNotifs && typeof rawNotifs === 'object' && Array.isArray((rawNotifs as Record<string, unknown>).notifications))
            ? (rawNotifs as unknown as { notifications: NotificationDto[] }).notifications
            : [];

        setData({
          user: userResult.value.user,
          applications: resolvedApps,
          notifications: resolvedNotifs,
        });
        setLoading(false);
      } catch {
        if (!cancelled) {
          clearToken();
          router.replace('/login');
        }
      }
    }

    load();
    return () => { cancelled = true; };
  }, [router]);

  const handleLogout = useCallback(() => {
    clearToken();
    router.replace('/login');
  }, [router]);

  const handleMarkRead = useCallback(
    async (notifId: number) => {
      setData((prev) => {
        if (!prev) return prev;
        return {
          ...prev,
          notifications: (prev.notifications ?? []).map((n) => {
            if (notifId === -1 || n.id === notifId) {
              return { ...n, read_at: new Date().toISOString() };
            }
            return n;
          }),
        };
      });

      try {
        await markNotificationsRead(notifId === -1 ? undefined : notifId);
      } catch {
        // Silent catch for notification read sync
      }
    },
    []
  );

  if (loading || !data) {
    return <DashboardSkeleton />;
  }

  const { user, applications, notifications } = data;
  const safeNotifications: NotificationDto[] = Array.isArray(notifications) ? notifications : [];
  const safeApplications: CreditApplicationDto[] = Array.isArray(applications) ? applications : [];
  const displayName = user.first_name || user.email.split('@')[0];
  const initials = (user.first_name?.[0] || user.email[0] || 'U').toUpperCase();
  const lockedApp = safeApplications.find(
    (a) => a.status === 'APPOINTMENT_LOCKED'
  );
  const unreadCount = safeNotifications.filter((n) => !n.read_at).length;

  return (
    <div className="min-h-screen bg-[#F4F6F8] text-[#1E2D3D]">
      <DashboardHeader
        user={user}
        applicationsCount={safeApplications.length}
        unreadCount={unreadCount}
      />

      {/* ─── MAIN CONTENT ─── */}
      <main id="main" className="mx-auto max-w-7xl px-4 sm:px-8 py-8 sm:py-10 space-y-8">
        {/* Editorial Welcome Header */}
        <section className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 border-b border-[#E0E4E9] pb-6">
          <div className="space-y-1">
            <p className="overline">Espace Personnel Sécurisé</p>
            <h1 className="font-display text-3xl sm:text-4xl font-light text-[#0C1825]">
              Bonjour, <span className="font-normal italic text-[#C0272D]">{displayName}</span>
            </h1>
            <p className="text-xs text-[#3D5166]">
              Bienvenue sur votre portail BTS Bank — {formatFrenchDate()}.
            </p>
          </div>

          <div className="flex items-center gap-3">
            <Link
              href="/applications"
              className="btn-red text-xs inline-flex items-center gap-1.5 shadow-xs"
              style={{ padding: '8px 18px' }}
            >
              <PlusCircle className="size-4" />
              <span>Nouvelle demande</span>
            </Link>
          </div>
        </section>

        {/* 1. Account Activity & Key Real Metrics */}
        <AccountOverview
          applications={safeApplications}
          phoneVerified={user.phone_verified}
        />

        {/* 2. Quick Actions */}
        <QuickActions
          hasLockedApp={!!lockedApp}
          lockedAppId={lockedApp?.id}
        />

        {/* 3. Two-Column Modular Layout */}
        <div className="grid grid-cols-1 lg:grid-cols-5 gap-6">
          {/* Left Column (3/5): Credit Applications & Appointments */}
          <div className="space-y-6 lg:col-span-3">
            <CreditAppsSummary applications={safeApplications} />
            <AppointmentsSummary applications={safeApplications} />
          </div>

          {/* Right Column (2/5): Notifications & Messages */}
          <div className="space-y-6 lg:col-span-2">
            <NotificationsPanel
              notifications={safeNotifications}
              onMarkRead={handleMarkRead}
            />
            <MessagesEntry applications={safeApplications} />
          </div>
        </div>
      </main>
    </div>
  );
}
