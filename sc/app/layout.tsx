import type { Metadata } from 'next';
import './globals.css';
import { SecurityShell } from '@/components/security-shell';

export const metadata: Metadata = {
  title: {
    default: 'BTS Bank — Security Center (SC)',
    template: '%s · BTS Bank Security',
  },
  description: 'Portail de Sécurité Opérationnelle (SOC) et d’Audit BTS Bank — SC Team',
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="fr" className="h-full antialiased">
      <body className="min-h-full flex flex-col bg-[#F4F6F8] text-[#0C1825]">
        <a href="#main" className="sr-only z-[100] rounded-lg bg-white px-4 py-2 text-sm font-bold text-[#C0272D] shadow-lg focus:not-sr-only focus:fixed focus:left-4 focus:top-4">
          Aller au contenu
        </a>
        <SecurityShell>{children}</SecurityShell>
      </body>
    </html>
  );
}
