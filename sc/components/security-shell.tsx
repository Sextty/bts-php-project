'use client';

import { usePathname } from 'next/navigation';
import { SecuritySidebar } from '@/components/security-sidebar';

export function SecurityShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const isLoginPage = pathname === '/login';

  if (isLoginPage) {
    return <>{children}</>;
  }

  return (
    <div className="sc-root">
      <SecuritySidebar />
      <div className="lg:pl-72">
        <main id="main" className="min-h-screen px-4 pb-12 pt-20 sm:px-6 lg:p-8">
          {children}
        </main>
      </div>
    </div>
  );
}
