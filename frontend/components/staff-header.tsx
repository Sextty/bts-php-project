'use client';

import { useRouter } from 'next/navigation';
import { LogOut } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Logo } from '@/components/logo';
import { staffLogout } from '@/lib/api/staff';
import { clearStaffToken } from '@/lib/auth/staff-token';

export function StaffHeader({ role }: { role: 'staff' | 'admin' }) {
  const router = useRouter();

  async function handleLogout() {
    try {
      await staffLogout();
    } finally {
      clearStaffToken();
      router.push('/staff/login');
    }
  }

  return (
    <header className="flex items-center justify-between border-b border-border/60 bg-card px-6 py-4">
      <div className="flex items-center gap-2.5">
        <Logo size={28} />
        <span className="text-sm font-semibold tracking-tight">
          BTS <span className="font-normal text-muted-foreground">Bank</span>
        </span>
        <Badge variant="outline" className="ml-1 capitalize">
          {role}
        </Badge>
      </div>
      <Button onClick={handleLogout} variant="ghost" size="sm" className="gap-1.5 text-muted-foreground">
        <LogOut className="size-4" />
        Log out
      </Button>
    </header>
  );
}
