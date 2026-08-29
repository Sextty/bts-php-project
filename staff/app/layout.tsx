import type { Metadata } from 'next';
import { Inter, Fraunces } from 'next/font/google';
import './globals.css';

const inter = Inter({
  variable: '--font-inter',
  subsets: ['latin'],
  display: 'swap',
});

const fraunces = Fraunces({
  variable: '--font-fraunces',
  subsets: ['latin'],
  axes: ['SOFT', 'WONK', 'opsz'],
  display: 'swap',
});

export const metadata: Metadata = {
  title: {
    default: 'BTS Bank — Portail Conseillers & Administration',
    template: '%s · BTS Bank Staff',
  },
  description: 'Portail de gestion et d’instruction des demandes de crédit — BTS Bank.',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html
      lang="fr"
      data-scroll-behavior="smooth"
      className={`${inter.variable} ${fraunces.variable} font-sans h-full antialiased`}
    >
      <body className="staff-root min-h-full flex flex-col text-[#1E2D3D]" suppressHydrationWarning>
        <a
          href="#main"
          className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-[#C0272D] focus:text-white focus:px-4 focus:py-2 focus:ring-2 focus:ring-[#C0272D]"
        >
          Aller au contenu principal
        </a>
        {children}
      </body>
    </html>
  );
}
