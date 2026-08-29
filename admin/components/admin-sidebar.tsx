'use client';

import { useEffect, useState, useCallback } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import {
  LayoutDashboard,
  FolderOpen,
  Activity,
  MessageSquareText,
  Bell,
  LogOut,
  Menu,
  X,
  Shield,
  ChevronRight,
  UserX,
  CalendarClock,
  AlertCircle,
  ChartNoAxesCombined,
} from 'lucide-react';
import { Logo } from '@/components/logo';
import { staffLogout } from '@/lib/api/staff';
import { clearStaffToken, getStaffToken } from '@/lib/auth/staff-token';
import { getNotifications, markNotificationsRead, type NotificationDto } from '@/lib/api/notifications';
import { cn } from '@/lib/utils';

const NAV_ITEMS = [
  { href: '/', label: 'Tableau de bord', icon: LayoutDashboard, exact: true },
  { href: '/applications', label: 'Dossiers', icon: FolderOpen },
  { href: '/appointments', label: 'Rendez-vous', icon: CalendarClock },
  { href: '/analytics', label: 'Analytics', icon: ChartNoAxesCombined },
  { href: '/banned-users', label: 'Clients Bannis', icon: UserX },
  { href: '/activity', label: "Journal d'audit", icon: Activity },
  { href: '/reports', label: 'Discussions', icon: MessageSquareText },
] as const;

export function AdminSidebar() {
  const router = useRouter();
  const pathname = usePathname();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [notifications, setNotifications] = useState<NotificationDto[]>([]);
  const [notifOpen, setNotifOpen] = useState(false);
  const [notificationError, setNotificationError] = useState<string | null>(null);

  const unreadCount = (notifications || []).filter((n) => !n?.read_at).length;

  // Fetch notifications on mount
  useEffect(() => {
    if (!getStaffToken()) return;
    getNotifications()
      .then((res) => {
        setNotificationError(null);
        if (Array.isArray(res)) {
          setNotifications(res);
        } else if (res && Array.isArray((res as { notifications?: NotificationDto[] }).notifications)) {
          setNotifications((res as { notifications: NotificationDto[] }).notifications);
        } else {
          setNotifications([]);
        }
      })
      .catch(() => {
        setNotifications([]);
        setNotificationError('Notifications indisponibles. Réessayez plus tard.');
      });
  }, []);

  useEffect(() => {
    if (!mobileOpen && !notifOpen) return;

    function handleEscape(event: KeyboardEvent) {
      if (event.key !== 'Escape') return;
      setMobileOpen(false);
      setNotifOpen(false);
    }

    window.addEventListener('keydown', handleEscape);
    return () => window.removeEventListener('keydown', handleEscape);
  }, [mobileOpen, notifOpen]);

  const handleLogout = useCallback(async () => {
    try {
      await staffLogout();
    } catch {
      // Logout locally must succeed even if server call fails
    } finally {
      clearStaffToken();
      router.push('/login');
    }
  }, [router]);

  async function handleMarkAllRead() {
    try {
      await markNotificationsRead();
      setNotifications((prev) => (prev || []).map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })));
      setNotificationError(null);
    } catch {
      setNotificationError('Impossible de marquer les notifications comme lues.');
    }
  }

  function isActive(href: string, exact?: boolean): boolean {
    if (exact) return pathname === href;
    return pathname === href || pathname?.startsWith(href + '/');
  }

  const sidebarContent = (
    <>
      {/* ── Brand Header ── */}
      <div className="flex items-center gap-3 border-b border-slate-200/70 px-5 pb-5 pt-6">
        <div className="flex size-11 items-center justify-center rounded-2xl border border-red-100 bg-white shadow-sm">
          <Logo size={30} />
        </div>
        <div className="min-w-0">
          <p className="text-sm font-bold tracking-tight text-[#0C1825]">
            BTS <span className="font-normal text-[#3D5166]">Bank</span>
          </p>
          <div className="flex items-center gap-1 mt-0.5">
            <Shield className="size-3 text-[#C0272D]" />
            <span className="text-[10px] font-bold uppercase tracking-wider text-[#C0272D]">Administration</span>
          </div>
        </div>
      </div>

      {/* ── Navigation ── */}
      <nav aria-label="Navigation principale" className="flex-1 space-y-1 px-3 py-5">
        {NAV_ITEMS.map((item) => {
          const active = isActive(item.href, 'exact' in item ? item.exact : false);
          const Icon = item.icon;
          return (
            <Link
              key={item.href}
              href={item.href}
              onClick={() => setMobileOpen(false)}
              aria-current={active ? 'page' : undefined}
              className={cn(
                'group relative flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-medium transition-all duration-200 focus-visible:ring-2 focus-visible:ring-[#C0272D]/30',
                active
                  ? 'bg-gradient-to-r from-[#FDF2F2] to-white text-[#C0272D] shadow-sm ring-1 ring-red-100'
                  : 'text-[#3D5166] hover:bg-white hover:text-[#0C1825] hover:shadow-sm'
              )}
            >
              {active && (
                <div className="absolute left-0 top-1/2 -translate-y-1/2 w-[3px] h-5 rounded-r-full bg-[#C0272D]" />
              )}
              <Icon className={cn('size-[18px] shrink-0', active ? 'text-[#C0272D]' : 'text-[#3D5166] group-hover:text-[#0C1825]')} />
              <span className="truncate">{item.label}</span>
              {active && <ChevronRight className="size-3.5 ml-auto text-[#C0272D]/50" />}
            </Link>
          );
        })}
      </nav>

      {/* ── Notification Bell ── */}
      <div className="px-3 pb-2">
        <div className="relative">
          <button
            type="button"
            onClick={() => setNotifOpen(!notifOpen)}
            aria-expanded={notifOpen}
            aria-haspopup="dialog"
            className={cn(
              'w-full flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-medium transition-all focus-visible:ring-2 focus-visible:ring-[#C0272D]/30',
              notifOpen
                ? 'bg-[#FDF2F2] text-[#C0272D]'
                : 'text-[#3D5166] hover:bg-[#F4F6F8] hover:text-[#0C1825]'
            )}
          >
            <Bell className="size-[18px] shrink-0" />
            <span className="truncate">Notifications</span>
            {unreadCount > 0 && (
              <span className="ml-auto flex items-center justify-center min-w-5 h-5 rounded-full bg-[#C0272D] text-white text-[10px] font-bold px-1.5">
                {unreadCount > 99 ? '99+' : unreadCount}
              </span>
            )}
          </button>

          {/* Notification Dropdown */}
          {notifOpen && (
            <div role="dialog" aria-label="Notifications" className="absolute bottom-full left-0 right-0 z-50 mb-2 max-h-80 overflow-hidden rounded-2xl border border-[#E0E4E9] bg-white shadow-xl">
              <div className="flex items-center justify-between px-4 py-3 border-b border-[#E0E4E9]">
                <h4 className="text-xs font-bold uppercase tracking-wider text-[#0C1825]">Notifications</h4>
                {unreadCount > 0 && (
                  <button
                    type="button"
                    onClick={handleMarkAllRead}
                    className="text-[10px] font-semibold text-[#C0272D] hover:underline"
                  >
                    Tout marquer comme lu
                  </button>
                )}
              </div>
              <div className="overflow-y-auto max-h-60">
                {notificationError && (
                  <div role="alert" className="flex items-start gap-2 border-b border-red-100 bg-red-50 px-4 py-3 text-[11px] font-medium text-red-700">
                    <AlertCircle className="mt-0.5 size-3.5 shrink-0" />
                    <span>{notificationError}</span>
                  </div>
                )}
                {(notifications || []).length === 0 ? (
                  <div className="py-8 text-center">
                    <Bell className="size-6 text-[#E0E4E9] mx-auto mb-2" />
                    <p className="text-xs text-[#3D5166]">Aucune notification</p>
                  </div>
                ) : (
                  <ul>
                    {(notifications || []).slice(0, 10).map((n) => (
                      <li
                        key={n.id}
                        className={cn(
                          'px-4 py-3 border-b border-[#F4F6F8] last:border-b-0 transition-colors',
                          !n.read_at ? 'bg-[#FDF2F2]/30' : ''
                        )}
                      >
                        <div className="flex items-start gap-2">
                          {!n.read_at && <div className="mt-1.5 size-2 rounded-full bg-[#C0272D] shrink-0" />}
                          <div className="min-w-0">
                            <p className="text-xs font-semibold text-[#0C1825] line-clamp-1">{n.title}</p>
                            <p className="text-[11px] text-[#3D5166] line-clamp-2 mt-0.5">{n.body}</p>
                            <p className="text-[10px] text-[#3D5166]/60 mt-1">{formatTimeAgo(n.created_at)}</p>
                          </div>
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* ── Logout ── */}
      <div className="mx-3 border-t border-[#E0E4E9] px-3 pb-5 pt-3">
        <button
          type="button"
          onClick={handleLogout}
          className="flex w-full items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-medium text-[#3D5166] transition-all hover:bg-red-50 hover:text-red-600 focus-visible:ring-2 focus-visible:ring-red-200"
        >
          <LogOut className="size-[18px]" />
          <span>Déconnexion</span>
        </button>
      </div>
    </>
  );

  return (
    <>
      {/* ── Mobile hamburger trigger ── */}
      <button
        type="button"
        onClick={() => setMobileOpen(true)}
        className="fixed left-4 top-4 z-40 flex size-11 items-center justify-center rounded-2xl border border-[#E0E4E9] bg-white/95 text-[#0C1825] shadow-lg backdrop-blur transition-colors hover:bg-[#F4F6F8] lg:hidden"
        aria-label="Ouvrir le menu"
        aria-controls="admin-mobile-navigation"
        aria-expanded={mobileOpen}
      >
        <Menu className="size-5" />
      </button>

      {/* ── Desktop Sidebar ── */}
      <aside className="z-30 hidden border-r border-slate-200/80 bg-slate-50/90 shadow-[8px_0_30px_rgba(15,23,42,0.035)] backdrop-blur-xl lg:fixed lg:inset-y-0 lg:left-0 lg:flex lg:w-72 lg:flex-col">
        {sidebarContent}
      </aside>

      {/* ── Mobile Overlay ── */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button
            type="button"
            aria-label="Fermer le menu"
            className="absolute inset-0 bg-slate-950/35 backdrop-blur-sm"
            onClick={() => setMobileOpen(false)}
          />
          <aside id="admin-mobile-navigation" role="dialog" aria-modal="true" aria-label="Menu d’administration" className="absolute inset-y-0 left-0 flex w-72 flex-col bg-slate-50 shadow-2xl animate-in slide-in-from-left duration-200">
            <button
              type="button"
              onClick={() => setMobileOpen(false)}
              className="absolute top-4 right-4 size-8 rounded-lg flex items-center justify-center text-[#3D5166] hover:bg-[#F4F6F8]"
              aria-label="Fermer le menu"
            >
              <X className="size-5" />
            </button>
            {sidebarContent}
          </aside>
        </div>
      )}
    </>
  );
}

function formatTimeAgo(iso: string): string {
  const now = Date.now();
  const then = new Date(iso).getTime();
  const diffMs = now - then;
  const diffMin = Math.floor(diffMs / 60000);
  if (diffMin < 1) return "À l'instant";
  if (diffMin < 60) return `Il y a ${diffMin} min`;
  const diffHr = Math.floor(diffMin / 60);
  if (diffHr < 24) return `Il y a ${diffHr}h`;
  const diffDays = Math.floor(diffHr / 24);
  if (diffDays < 7) return `Il y a ${diffDays}j`;
  return new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
}
