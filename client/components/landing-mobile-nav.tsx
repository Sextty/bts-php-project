'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Menu, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { buttonVariants } from '@/components/ui/button';

const NAV_ITEMS = [
  { href: '/', label: 'Accueil' },
  { href: '/register', label: 'Nos crédits' },
  { href: '/dashboard', label: 'Services' },
  { href: '/login', label: 'À propos' },
  { href: '/login', label: 'Contact' },
];

export function LandingMobileNav() {
  const [open, setOpen] = useState(false);

  return (
    <div className="md:hidden">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        className="flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        aria-label={open ? 'Fermer le menu' : 'Ouvrir le menu'}
        aria-expanded={open}
      >
        {open ? <X className="size-5" /> : <Menu className="size-5" />}
      </button>

      {open && (
        <nav
          className="absolute left-0 right-0 top-full z-50 border-b border-border/60 bg-card px-4 pb-5 pt-3 shadow-lg"
          aria-label="Navigation mobile"
        >
          <ul className="space-y-1">
            {NAV_ITEMS.map((item) => (
              <li key={item.label}>
                <Link
                  href={item.href}
                  onClick={() => setOpen(false)}
                  className="flex items-center rounded-md px-3 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-accent/40"
                >
                  {item.label}
                </Link>
              </li>
            ))}
          </ul>
          <div className="mt-4 flex flex-col gap-2 px-3">
            <Link
              href="/login"
              onClick={() => setOpen(false)}
              className={cn(buttonVariants({ variant: 'outline' }), 'w-full justify-center')}
            >
              Se connecter
            </Link>
            <Link
              href="/register"
              onClick={() => setOpen(false)}
              className={cn(buttonVariants({ variant: 'default' }), 'w-full justify-center')}
            >
              Créer un compte
            </Link>
          </div>
        </nav>
      )}
    </div>
  );
}
