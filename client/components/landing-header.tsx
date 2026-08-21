import Link from 'next/link';
import { Logo } from '@/components/logo';
import { LandingMobileNav } from '@/components/landing-mobile-nav';

const NAV_ITEMS = [
  { href: '#accueil', label: 'Accueil' },
  { href: '#solutions', label: 'Nos crédits' },
  { href: '#services', label: 'Services' },
  { href: '#pourquoi', label: 'À propos' },
  { href: '#contact', label: 'Contact' },
];

export function LandingHeader() {
  return (
    <header className="sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-[#E0E4E9] shadow-xs">
      <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-3.5 sm:px-8">
        {/* Logo */}
        <Link href="/" className="flex items-center gap-3 shrink-0">
          <Logo size={36} />
          <span className="font-display text-lg font-semibold tracking-tight text-[#0C1825]">
            BTS <span className="font-sans font-normal text-xs text-[#3D5166]">Bank</span>
          </span>
        </Link>

        {/* Desktop navigation */}
        <nav className="hidden items-center gap-1 md:flex" aria-label="Navigation principale">
          {NAV_ITEMS.map((item) => (
            <Link
              key={item.label}
              href={item.href}
              className="rounded-md px-3.5 py-1.5 text-sm font-medium text-[#3D5166] transition-colors hover:text-[#C0272D] hover:bg-[#FDF2F2]"
            >
              {item.label}
            </Link>
          ))}
        </nav>

        {/* Desktop auth buttons */}
        <div className="hidden items-center gap-3 md:flex">
          <Link
            href="/login"
            className="btn-outline text-xs"
            style={{ padding: "8px 18px" }}
          >
            Se connecter
          </Link>
          <Link
            href="/register"
            className="btn-red text-xs"
            style={{ padding: "8px 18px" }}
          >
            Créer un compte
          </Link>
        </div>

        {/* Mobile hamburger */}
        <LandingMobileNav />
      </div>
    </header>
  );
}
