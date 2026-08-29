'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { getStaffToken } from '@/lib/auth/staff-token';
import { PageLoading } from '@/components/page-loading';

export default function StaffHomePage() {
  const router = useRouter();

  useEffect(() => {
    router.replace(getStaffToken() ? '/dashboard' : '/login');
  }, [router]);

  return <PageLoading />;
}