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
      router.push('/staff/login');
    }
  }

  return (
    <header className="flex items-center justify-between border-b border-border/60 bg-card px-6 py-4">
      <div className="flex items-center gap-4">
        <div className="flex items-center gap-2.5">
          <Logo size={28} />
          <span className="text-sm font-semibold tracking-tight">
            BTS <span className="font-normal text-muted-foreground">Bank</span>
          </span>
          <Badge variant="outline" className="ml-1 capitalize">
            {role}
          </Badge>
        </div>
        <nav className="flex items-center gap-1 text-sm">
          {(() => {
            const applicationsHref = role === 'admin' ? '/staff/admin' : '/staff/dashboard';
            return (
              <Link
                href={applicationsHref}
                className={cn(
                  'rounded-md px-2.5 py-1.5 font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
                  pathname?.startsWith(applicationsHref) && 'bg-accent text-accent-foreground hover:bg-accent'
                )}
              >
                Applications
              </Link>
            );
          })()}
          <Link
            href="/staff/reports"
            className={cn(
              'rounded-md px-2.5 py-1.5 font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
              pathname?.startsWith('/staff/reports') && 'bg-accent text-accent-foreground hover:bg-accent'
            )}
          >
            Reports
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
