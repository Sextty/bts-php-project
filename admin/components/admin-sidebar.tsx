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
} from 'lucide-react';
import { Logo } from '@/components/logo';
import { staffLogout } from '@/lib/api/staff';
import { clearStaffToken, getStaffToken } from '@/lib/auth/staff-token';
import { getNotifications, markNotificationsRead, type NotificationDto } from '@/lib/api/notifications';
import { cn } from '@/lib/utils';

const NAV_ITEMS = [
  { href: '/', label: 'Tableau de bord', icon: LayoutDashboard, exact: true },
  { href: '/applications', label: 'Dossiers', icon: FolderOpen },
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

  const unreadCount = (notifications || []).filter((n) => !n?.read_at).length;

  // Fetch notifications on mount
  useEffect(() => {
    if (!getStaffToken()) return;
    getNotifications()
      .then((res) => {
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
      });
  }, []);

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
    } catch {}
  }

  function isActive(href: string, exact?: boolean): boolean {
    if (exact) return pathname === href;
    return pathname === href || pathname?.startsWith(href + '/');
  }

  const sidebarContent = (
    <>
      {/* ── Brand Header ── */}
      <div className="flex items-center gap-3 px-5 pt-6 pb-4">
        <Logo size={32} />
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
      <nav aria-label="Navigation principale" className="flex-1 px-3 pt-4 space-y-1">
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
                'group relative flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium transition-all',
                active
                  ? 'bg-[#FDF2F2] text-[#C0272D] shadow-xs'
                  : 'text-[#3D5166] hover:bg-[#F4F6F8] hover:text-[#0C1825]'
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
            className={cn(
              'w-full flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium transition-all',
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
            <div className="absolute bottom-full left-0 right-0 mb-2 bg-white rounded-xl border border-[#E0E4E9] shadow-lg max-h-80 overflow-hidden z-50">
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
      <div className="px-3 pb-5 pt-2 border-t border-[#E0E4E9] mx-3">
        <button
          type="button"
          onClick={handleLogout}
          className="w-full flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-[#3D5166] hover:bg-red-50 hover:text-red-600 transition-all"
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
        className="fixed top-4 left-4 z-40 lg:hidden size-10 rounded-xl bg-white border border-[#E0E4E9] shadow-sm flex items-center justify-center text-[#0C1825] hover:bg-[#F4F6F8] transition-colors"
        aria-label="Ouvrir le menu"
      >
        <Menu className="size-5" />
      </button>

      {/* ── Desktop Sidebar ── */}
      <aside className="hidden lg:flex lg:flex-col lg:fixed lg:inset-y-0 lg:left-0 lg:w-64 bg-white border-r border-[#E0E4E9] z-30">
        {sidebarContent}
      </aside>

      {/* ── Mobile Overlay ── */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <div
            className="absolute inset-0 bg-black/30 backdrop-blur-sm"
            onClick={() => setMobileOpen(false)}
          />
          <aside className="absolute inset-y-0 left-0 w-72 bg-white shadow-2xl flex flex-col animate-in slide-in-from-left duration-200">
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
