'use client';

import { useEffect, useState } from 'react';
import { useRouter, usePathname } from 'next/navigation';
import Link from 'next/link';
import {
  LogOut,
  LayoutDashboard,
  FileText,
  Calendar,
  User,
  Bell,
  Menu,
  X,
  ShieldCheck,
} from 'lucide-react';
import { Logo } from '@/components/logo';
import { logout, getCurrentUser, type UserDto } from '@/lib/api/auth';
import { listApplications } from '@/lib/api/credit-applications';
import { getNotifications, type NotificationDto } from '@/lib/api/notifications';
import { clearToken, getToken } from '@/lib/auth/token';
import { cn } from '@/lib/utils';

export interface DashboardHeaderProps {
  user?: UserDto | null;
  applicationsCount?: number;
  unreadCount?: number;
}

export function DashboardHeader({
  user: initialUser,
  applicationsCount: initialAppsCount,
  unreadCount: initialUnreadCount,
}: DashboardHeaderProps = {}) {
  const router = useRouter();
  const pathname = usePathname();

  const [user, setUser] = useState<UserDto | null>(initialUser ?? null);
  const [appsCount, setAppsCount] = useState<number>(initialAppsCount ?? 0);
  const [unreadCount, setUnreadCount] = useState<number>(initialUnreadCount ?? 0);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  useEffect(() => {
    if (initialUser) setUser(initialUser);
    if (typeof initialAppsCount === 'number') setAppsCount(initialAppsCount);
    if (typeof initialUnreadCount === 'number') setUnreadCount(initialUnreadCount);
  }, [initialUser, initialAppsCount, initialUnreadCount]);

  useEffect(() => {
    // If not passed as props, lazily load current user & counts if token exists
    if (!getToken()) return;

    if (!initialUser) {
      getCurrentUser()
        .then(({ user }) => setUser(user))
        .catch(() => {});
    }

    if (typeof initialAppsCount !== 'number') {
      listApplications()
        .then((res) => {
          const apps = Array.isArray(res)
            ? res
            : res && typeof res === 'object' && Array.isArray((res as Record<string, unknown>).applications)
            ? (res as { applications: unknown[] }).applications
            : [];
          setAppsCount(apps.length);
        })
        .catch(() => {});
    }

    if (typeof initialUnreadCount !== 'number') {
      getNotifications()
        .then((notifs: NotificationDto[]) => {
          const safeNotifs = Array.isArray(notifs) ? notifs : [];
          setUnreadCount(safeNotifs.filter((n) => !n.read_at).length);
        })
        .catch(() => {});
    }
  }, [initialUser, initialAppsCount, initialUnreadCount]);

  async function handleLogout() {
    try {
      await logout();
    } catch {
      // Clean up token even if network fails
    } finally {
      clearToken();
      router.push('/login');
    }
  }

  const displayName = user
    ? `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.email.split('@')[0]
    : 'Mon Espace';

  const initials = user
    ? (user.first_name?.[0] || user.email[0] || 'U').toUpperCase()
    : 'U';

  const navLinks = [
    {
      href: '/dashboard',
      label: 'Tableau de bord',
      icon: LayoutDashboard,
      active: pathname === '/dashboard',
    },
    {
      href: '/applications',
      label: appsCount > 0 ? `Mes demandes (${appsCount})` : 'Mes demandes',
      icon: FileText,
      active: pathname === '/applications' || (pathname.startsWith('/applications') && pathname !== '/applications'),
    },
    {
      href: '/appointments',
      label: 'Rendez-vous',
      icon: Calendar,
      active: pathname === '/appointments',
    },
    {
      href: '/profile',
      label: 'Profil',
      icon: User,
      active: pathname === '/profile',
    },
  ];

  return (
    <header className="sticky top-0 z-40 border-b border-[#E0E4E9] bg-white/95 backdrop-blur-md">
      <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-8">
        {/* 1. Brand Logo */}
        <div className="flex items-center gap-6">
          <Link href="/dashboard" className="flex items-center gap-3 shrink-0">
            <Logo size={32} />
            <div className="flex items-baseline gap-1.5">
              <span className="font-display text-lg font-semibold tracking-tight text-[#0C1825]">
                BTS <span className="font-sans font-normal text-xs text-[#3D5166]">Bank</span>
              </span>
              <span className="hidden sm:inline-block text-[10px] font-semibold text-[#C0272D] bg-[#FDF2F2] px-2 py-0.5 rounded">
                Espace Client
              </span>
            </div>
          </Link>

          {/* 2. Desktop Navigation */}
          <nav className="hidden md:flex items-center gap-1" aria-label="Navigation principale">
            {navLinks.map((link) => {
              const Icon = link.icon;
              return (
                <Link
                  key={link.href}
                  href={link.href}
                  className={cn(
                    'flex items-center gap-1.5 rounded-md px-3.5 py-1.5 text-xs font-semibold transition-colors',
                    link.active
                      ? 'bg-[#FDF2F2] text-[#C0272D]'
                      : 'text-[#3D5166] hover:bg-[#F4F6F8] hover:text-[#0C1825]'
                  )}
                >
                  <Icon className="size-3.5" />
                  {link.label}
                </Link>
              );
            })}
          </nav>
        </div>

        {/* 3. Right Side Actions & User */}
        <div className="flex items-center gap-3">
          {/* Notification Bell */}
          <Link
            href="/dashboard#notifications"
            className="relative p-2 text-[#3D5166] hover:text-[#0C1825] rounded-lg hover:bg-[#F4F6F8] transition-colors"
            aria-label="Notifications"
          >
            <Bell className="size-4.5" />
            {unreadCount > 0 && (
              <span className="absolute top-1 right-1 flex size-4 items-center justify-center rounded-full bg-[#C0272D] text-[9px] font-bold text-white">
                {unreadCount > 9 ? '9+' : unreadCount}
              </span>
            )}
          </Link>

          {/* User Avatar & Status */}
          {user && (
            <Link
              href="/profile"
              className="hidden sm:flex items-center gap-2.5 pl-2 border-l border-[#E0E4E9] hover:opacity-80 transition-opacity"
            >
              <div className="size-8 rounded-full bg-[#FDF2F2] text-[#C0272D] font-bold text-xs flex items-center justify-center border border-[#FECACA]">
                {initials}
              </div>
              <div className="text-left leading-tight">
                <div className="text-xs font-semibold text-[#0C1825]">{displayName}</div>
                <div className="text-[10px] text-[#3D5166] flex items-center gap-1">
                  {user.phone_verified ? (
                    <span className="text-emerald-700 font-medium flex items-center gap-0.5">
                      <ShieldCheck className="size-3" /> Vérifié
                    </span>
                  ) : (
                    <span className="text-amber-700">En attente OTP</span>
                  )}
                </div>
              </div>
            </Link>
          )}

          {/* Logout button */}
          <button
            type="button"
            className="btn-outline text-xs hidden sm:inline-flex items-center gap-1.5"
            style={{ padding: '6px 14px' }}
            onClick={handleLogout}
            aria-label="Se déconnecter"
          >
            <LogOut className="size-3.5" />
            <span>Quitter</span>
          </button>

          {/* Mobile Menu Toggle */}
          <button
            type="button"
            className="p-2 text-[#3D5166] md:hidden"
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            aria-label="Menu"
          >
            {mobileMenuOpen ? <X className="size-5" /> : <Menu className="size-5" />}
          </button>
        </div>
      </div>

      {/* 4. Mobile Menu Drawer */}
      {mobileMenuOpen && (
        <nav
          className="border-t border-[#E0E4E9] bg-white px-4 pb-4 pt-3 md:hidden space-y-1"
          aria-label="Navigation mobile"
        >
          {navLinks.map((link) => {
            const Icon = link.icon;
            return (
              <Link
                key={link.href}
                href={link.href}
                className={cn(
                  'flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold transition-colors',
                  link.active
                    ? 'bg-[#FDF2F2] text-[#C0272D]'
                    : 'text-[#3D5166] hover:bg-[#F4F6F8]'
                )}
                onClick={() => setMobileMenuOpen(false)}
              >
                <Icon className="size-4" />
                {link.label}
              </Link>
            );
          })}
          <div className="pt-2 border-t border-[#E0E4E9]">
            <button
              type="button"
              onClick={handleLogout}
              className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium text-[#C0272D] hover:bg-red-50"
            >
              <LogOut className="size-4" />
              Se déconnecter
            </button>
          </div>
        </nav>
      )}
    </header>
  );
}
