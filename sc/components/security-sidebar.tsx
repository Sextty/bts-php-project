'use client';

import { useEffect, useState, useCallback } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  LayoutDashboard,
  Network,
  ShieldAlert,
  Terminal,
  Activity,
  UserX,
  FileCheck2,
  LogOut,
  Menu,
  X,
  Shield,
  ChevronRight,
} from 'lucide-react';
import { Logo } from '@/components/logo';
import { clearSecurityToken, getSecurityUser } from '@/lib/auth/security-token';
import { securityLogout } from '@/lib/api/security';
import { cn } from '@/lib/utils';

const NAV_ITEMS = [
  { href: '/', label: 'Tableau de bord', icon: LayoutDashboard, exact: true },
  { href: '/network', label: 'SC Réseau & Télémétrie', icon: Network },
  { href: '/alerts', label: 'Alertes de Sécurité', icon: ShieldAlert },
  { href: '/osquery', label: 'Console Osquery SQL', icon: Terminal },
  { href: '/activity', label: "Journal d'audit", icon: Activity },
  { href: '/banned-users', label: 'Clients Bannis & Sessions', icon: UserX },
  { href: '/data-audit', label: 'Audit des Données', icon: FileCheck2 },
] as const;

export function SecuritySidebar() {
  const pathname = usePathname();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [user] = useState(() => getSecurityUser());

  const handleLogout = useCallback(async () => {
    try {
      await securityLogout();
    } finally {
      clearSecurityToken();
      window.location.replace('/login');
    }
  }, []);

  useEffect(() => {
    if (!mobileOpen) return;
    function closeOnEscape(event: KeyboardEvent) {
      if (event.key === 'Escape') setMobileOpen(false);
    }
    window.addEventListener('keydown', closeOnEscape);
    return () => window.removeEventListener('keydown', closeOnEscape);
  }, [mobileOpen]);

  function isActive(href: string, exact?: boolean): boolean {
    if (exact) return pathname === href;
    return pathname === href || pathname?.startsWith(href + '/');
  }

  const sidebarContent = (
    <>
      {/* ── Brand Header ── */}
      <div className="flex items-center gap-3 border-b border-slate-200/70 px-5 pb-5 pt-6">
        <div className="flex size-11 items-center justify-center rounded-2xl border border-red-100 bg-white shadow-sm"><Logo size={30} /></div>
        <div className="min-w-0">
          <p className="text-sm font-bold tracking-tight text-[#0C1825]">
            BTS <span className="font-normal text-[#3D5166]">Bank</span>
          </p>
          <div className="flex items-center gap-1.5 mt-0.5">
            <Shield className="size-3 text-[#C0272D]" />
            <span className="text-[10px] font-bold uppercase tracking-wider text-[#C0272D]">Security Center</span>
          </div>
        </div>
      </div>

      {/* ── Network Agent Live Status ── */}
      <div className="flex items-center justify-between border-b border-slate-200/70 bg-white/60 px-5 py-3">
        <div className="flex items-center gap-2">
          <span className="size-2 rounded-full bg-slate-400" aria-hidden="true" />
          <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-600">Console SOC</span>
        </div>
        <span className="text-[10px] text-slate-500 font-mono">PORT 3003</span>
      </div>

      {/* ── Navigation ── */}
      <nav aria-label="Navigation principale" className="flex-1 space-y-1 overflow-y-auto px-3 py-5">
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
                'group relative flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-medium transition-all focus-visible:ring-2 focus-visible:ring-[#C0272D]/30',
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

      {/* ── Operator Info ── */}
      <div className="px-4 py-3 bg-[#F8FAFC] border-t border-[#E0E4E9]">
        <div className="flex items-center gap-2.5">
          <div className="size-8 rounded-full bg-[#E0E4E9] flex items-center justify-center text-xs font-bold text-[#0C1825] shrink-0">
            {user?.first_name ? user.first_name[0] : 'S'}
          </div>
          <div className="min-w-0">
            <p className="text-xs font-semibold text-[#0C1825] truncate">
              {user ? `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.email : 'Opérateur Sécurité'}
            </p>
            <p className="text-[10px] text-[#C0272D] font-medium uppercase truncate">
              {user?.role === 'security' ? 'SC Security Team' : user?.role || 'Security Operator'}
            </p>
          </div>
        </div>
      </div>

      {/* ── Logout ── */}
      <div className="px-3 pb-5 pt-2 border-t border-[#E0E4E9]">
        <button
          type="button"
          onClick={handleLogout}
          className="flex w-full items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-medium text-[#3D5166] transition-all hover:bg-red-50 hover:text-red-600"
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
        aria-controls="security-mobile-navigation"
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
            className="absolute inset-0 bg-black/30 backdrop-blur-sm"
            onClick={() => setMobileOpen(false)}
          />
          <aside id="security-mobile-navigation" role="dialog" aria-modal="true" aria-label="Menu Security Center" className="absolute inset-y-0 left-0 flex w-72 flex-col bg-slate-50 shadow-2xl animate-in slide-in-from-left duration-200">
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
