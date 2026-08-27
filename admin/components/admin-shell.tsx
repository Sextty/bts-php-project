'use client';

import { usePathname } from 'next/navigation';
import { AdminSidebar } from '@/components/admin-sidebar';

/**
 * Conditionally wraps pages in the sidebar layout. The login page renders without any chrome;
 * all other routes get the persistent sidebar + offset main content area.
 */
export function AdminShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const isLoginPage = pathname === '/login';

  if (isLoginPage) {
    return <>{children}</>;
  }

  return (
    <div className="admin-root">
      <AdminSidebar />
      <div className="lg:pl-72">
        <main id="main" className="min-h-screen">
          {children}
        </main>
      </div>
    </div>
  );
}
