'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { CheckCircle2, FileText, Mail, Phone, ShieldCheck, XCircle } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { DashboardHeader } from '@/components/dashboard-header';
import { getCurrentUser, type UserDto } from '@/lib/api/auth';
import { clearToken, getToken } from '@/lib/auth/token';
import { cn } from '@/lib/utils';

function initials(user: UserDto): string {
  return `${user.first_name[0] ?? ''}${user.last_name[0] ?? ''}`.toUpperCase();
}

export default function DashboardPage() {
  const router = useRouter();
  const [user, setUser] = useState<UserDto | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }
    getCurrentUser()
      .then(({ user }) => setUser(user))
      .catch(() => {
        clearToken();
        router.replace('/login');
      })
      .finally(() => setLoading(false));
  }, [router]);

  if (loading) {
    return <div className="flex min-h-screen items-center justify-center text-muted-foreground">Loading…</div>;
  }

  if (!user) return null;

  return (
    <div className="min-h-screen bg-muted/30">
      <DashboardHeader />
      <main className="mx-auto max-w-2xl px-4 py-10">
        <Card className="mb-6 border-border/60 shadow-sm">
          <CardContent className="flex items-center justify-between py-5">
            <div className="flex items-center gap-3">
              <div className="flex size-10 items-center justify-center rounded-lg bg-accent">
                <FileText className="size-5 text-accent-foreground" />
              </div>
              <div>
                <p className="font-medium">Credit applications</p>
                <p className="text-sm text-muted-foreground">Start or continue an application</p>
              </div>
            </div>
            <Link href="/applications" className={cn(buttonVariants({ variant: 'outline' }))}>
              View
            </Link>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-sm">
          <CardHeader className="flex-row items-center gap-4 space-y-0">
            <Avatar className="size-14">
              <AvatarFallback className="bg-primary text-lg font-semibold text-primary-foreground">
                {initials(user)}
              </AvatarFallback>
            </Avatar>
            <div>
              <CardTitle className="text-xl">
                {user.first_name} {user.last_name}
              </CardTitle>
              <p className="text-sm text-muted-foreground">Welcome back</p>
            </div>
          </CardHeader>
          <Separator />
          <CardContent className="space-y-4 pt-6">
            <div className="flex items-center justify-between text-sm">
              <span className="flex items-center gap-2 text-muted-foreground">
                <Mail className="size-4" /> Email
              </span>
              <span className="font-medium">{user.email}</span>
            </div>
            <div className="flex items-center justify-between text-sm">
              <span className="flex items-center gap-2 text-muted-foreground">
                <Phone className="size-4" /> Phone
              </span>
              <span className="font-medium">{user.phone ?? '—'}</span>
            </div>
            <div className="flex items-center justify-between text-sm">
              <span className="flex items-center gap-2 text-muted-foreground">
                <ShieldCheck className="size-4" /> Phone verified
              </span>
              {user.phone_verified ? (
                <Badge className="gap-1 bg-accent text-accent-foreground">
                  <CheckCircle2 className="size-3.5" /> Verified
                </Badge>
              ) : (
                <Badge variant="outline" className="gap-1 text-muted-foreground">
                  <XCircle className="size-3.5" /> Not verified
                </Badge>
              )}
            </div>
            <div className="flex items-center justify-between text-sm">
              <span className="text-muted-foreground">Signed in via</span>
              <span className="font-medium capitalize">{user.auth_provider}</span>
            </div>
          </CardContent>
        </Card>
      </main>
    </div>
  );
}
