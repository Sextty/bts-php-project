'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { LogOut } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Logo } from '@/components/logo';
import { staffLogout } from '@/lib/api/staff';
import { clearStaffToken } from '@/lib/auth/staff-token';
import { cn } from '@/lib/utils';

export function StaffHeader({ role }: { role: 'staff' | 'admin' }) {
  const router = useRouter();
  const pathname = usePathname();

  async function handleLogout() {
    try {
      await staffLogout();
    } catch {
      // Logging out locally must succeed even if the server call fails (e.g. the token already
      // expired) — the user's intent is to end their session either way.
    } finally {
      clearStaffToken();
      router.push('/login');
    }
  }

  return (
    <header className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-border/60 bg-card px-4 py-3 sm:px-6 sm:py-4">
      <div className="flex flex-wrap items-center gap-4">
        <div className="flex items-center gap-2.5">
          <Logo size={28} />
          <span className="text-sm font-semibold tracking-tight">
            BTS <span className="font-normal text-muted-foreground">Bank</span>
          </span>
          <Badge variant="outline" className="ml-1 capitalize">
            {role}
          </Badge>
        </div>
        <nav aria-label="Main navigation" className="flex flex-wrap items-center gap-1 text-xs sm:text-sm">
          <Link
            href="/"
            aria-current={pathname === '/' ? 'page' : undefined}
            className={cn(
              'rounded-md px-2.5 py-1.5 font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
              pathname === '/' && 'bg-accent text-accent-foreground font-semibold'
            )}
          >
            Applications
          </Link>
          <Link
            href="/appointments"
            aria-current={pathname?.startsWith('/appointments') ? 'page' : undefined}
            className={cn(
              'rounded-md px-2.5 py-1.5 font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
              pathname?.startsWith('/appointments') && 'bg-accent text-accent-foreground font-semibold text-[#C0272D]'
            )}
          >
            Rendez-vous & Agences
          </Link>
          <Link
            href="/reports"
            aria-current={pathname?.startsWith('/reports') ? 'page' : undefined}
            className={cn(
              'rounded-md px-2.5 py-1.5 font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
              pathname?.startsWith('/reports') && 'bg-accent text-accent-foreground font-semibold'
            )}
          >
            Signalements
          </Link>
          <Link
            href="/banned-users"
            aria-current={pathname?.startsWith('/banned-users') ? 'page' : undefined}
            className={cn(
              'rounded-md px-2.5 py-1.5 font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
              pathname?.startsWith('/banned-users') && 'bg-accent text-accent-foreground font-semibold'
            )}
          >
            Utilisateurs Bannis
          </Link>
          <Link
            href="/activity"
            aria-current={pathname?.startsWith('/activity') ? 'page' : undefined}
            className={cn(
              'rounded-md px-2.5 py-1.5 font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
              pathname?.startsWith('/activity') && 'bg-accent text-accent-foreground font-semibold'
            )}
          >
            Audit & Trafic
          </Link>
        </nav>
      </div>
      <Button onClick={handleLogout} variant="ghost" size="sm" className="gap-1.5 text-muted-foreground">
        <LogOut className="size-4" />
        Log out
      </Button>
    </header>
  );
}