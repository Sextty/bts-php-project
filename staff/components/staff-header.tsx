'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { LogOut, FileText, MessageSquare, Activity, UserCheck, Calendar, ChartNoAxesCombined } from 'lucide-react';
import { Logo } from '@/components/logo';
import { staffLogout } from '@/lib/api/staff';
import { clearStaffToken } from '@/lib/auth/staff-token';
import { cn } from '@/lib/utils';

const NAV_ITEMS = [
  { href: '/dashboard', label: 'Dossiers à instruire', icon: FileText },
  { href: '/appointments', label: 'Rendez-vous & Agences', icon: Calendar },
  { href: '/analytics', label: 'Analytics agence', icon: ChartNoAxesCombined },
  { href: '/reports', label: 'Discussions & Signalements', icon: MessageSquare },
  { href: '/activity', label: 'Journal d’Audit & Métriques', icon: Activity },
] as const;

export function StaffHeader({ role = 'staff' }: { role?: 'staff' | 'admin' | string }) {
  const router = useRouter();
  const pathname = usePathname();

  async function handleLogout() {
    try {
      await staffLogout();
    } catch {
      // Local session clears regardless
    } finally {
      clearStaffToken();
      router.push('/login');
    }
  }

  const roleLabel = role === 'admin' ? 'Administrateur' : 'Conseiller Agence';
  const visibleNavItems = NAV_ITEMS.filter((item) => item.href !== '/activity' || role === 'admin');

  return (
    <header className="sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 shadow-[0_1px_0_rgba(15,23,42,0.02)] backdrop-blur-xl">
      <div className="mx-auto flex max-w-[90rem] items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
        {/* Brand Logo & Area */}
        <div className="flex min-w-0 items-center gap-6">
          <Link href="/dashboard" className="flex shrink-0 items-center gap-3 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C0272D]/35">
            <Logo size={32} />
            <div className="flex items-baseline gap-1.5">
              <span className="font-display text-lg font-semibold tracking-tight text-[#0C1825]">
                BTS <span className="font-sans font-normal text-xs text-[#3D5166]">Bank</span>
              </span>
              <span className="hidden sm:inline-block text-[10px] font-semibold text-[#C0272D] bg-[#FDF2F2] px-2 py-0.5 rounded border border-[#FECACA]">
                Portail Interne Staff
              </span>
            </div>
          </Link>

          {/* Nav Links */}
          <nav aria-label="Navigation principale" className="hidden items-center gap-1 lg:flex">
            {visibleNavItems.map((item) => {
              const isActive =
                item.href === '/dashboard'
                  ? pathname === '/dashboard' || pathname.startsWith('/dashboard/')
                  : pathname?.startsWith(item.href);
              const Icon = item.icon;
              return (
                <Link
                  key={item.href}
                  href={item.href}
                  aria-current={isActive ? 'page' : undefined}
                  className={cn(
                    'flex min-h-9 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 text-xs font-semibold transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C0272D]/30',
                    isActive
                      ? 'bg-[#FDF2F2] text-[#C0272D]'
                      : 'text-[#3D5166] hover:bg-[#F4F6F8] hover:text-[#0C1825]'
                  )}
                >
                  <Icon className="size-3.5" />
                  {item.label}
                </Link>
              );
            })}
          </nav>
        </div>

        {/* Right side: Role badge & Logout */}
        <div className="flex shrink-0 items-center gap-3">
          <div className="hidden items-center gap-2 border-l border-[#E0E4E9] pl-3 sm:flex">
            <div className="size-7 rounded-full bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center border border-[#FECACA]">
              <UserCheck className="size-3.5" />
            </div>
            <div className="text-left leading-tight">
              <div className="text-xs font-semibold text-[#0C1825]">{roleLabel}</div>
              <div className="text-[10px] text-emerald-700 font-medium">Session Sécurisée</div>
            </div>
          </div>

          <button
            type="button"
            onClick={handleLogout}
            aria-label="Se déconnecter"
            className="btn-outline min-h-9 px-3 py-1.5 text-xs"
          >
            <LogOut className="size-3.5" />
            <span className="hidden sm:inline">Déconnexion</span>
          </button>
        </div>
      </div>

      <nav
        aria-label="Navigation mobile"
        className="mx-auto flex max-w-[90rem] gap-1 overflow-x-auto px-3 pb-2 lg:hidden sm:px-5"
      >
        {visibleNavItems.map((item) => {
          const isActive =
            item.href === '/dashboard'
              ? pathname === '/dashboard' || pathname.startsWith('/dashboard/')
              : pathname?.startsWith(item.href);
          const Icon = item.icon;
          return (
            <Link
              key={item.href}
              href={item.href}
              aria-current={isActive ? 'page' : undefined}
              className={cn(
                'flex min-h-9 shrink-0 items-center gap-1.5 rounded-lg px-3 text-[11px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C0272D]/30',
                isActive
                  ? 'bg-[#FDF2F2] text-[#C0272D]'
                  : 'text-[#3D5166] hover:bg-slate-100 hover:text-[#0C1825]'
              )}
            >
              <Icon className="size-3.5" />
              {item.label}
            </Link>
          );
        })}
      </nav>
    </header>
  );
}
