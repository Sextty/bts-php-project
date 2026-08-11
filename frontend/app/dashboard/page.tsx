'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { getCurrentUser, logout, type UserDto } from '@/lib/api/auth';
import { clearToken, getToken } from '@/lib/auth/token';

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

  async function handleLogout() {
    try {
      await logout();
    } finally {
      clearToken();
      router.push('/login');
    }
  }

  if (loading) {
    return <div className="flex min-h-screen items-center justify-center text-neutral-500">Loading…</div>;
  }

  if (!user) return null;

  return (
    <div className="flex min-h-screen items-start justify-center bg-neutral-50 p-4 pt-16">
      <Card className="w-full max-w-lg">
        <CardHeader>
          <CardTitle className="text-2xl">
            Welcome, {user.first_name} {user.last_name}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt className="text-neutral-500">Email</dt>
            <dd>{user.email}</dd>
            <dt className="text-neutral-500">Phone</dt>
            <dd>{user.phone ?? '—'}</dd>
            <dt className="text-neutral-500">Phone verified</dt>
            <dd>{user.phone_verified ? 'Yes' : 'No'}</dd>
            <dt className="text-neutral-500">Signed in via</dt>
            <dd className="capitalize">{user.auth_provider}</dd>
          </dl>
          <Button onClick={handleLogout} variant="outline">
            Log out
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
