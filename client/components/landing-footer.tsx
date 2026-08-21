import Link from 'next/link';
import { Logo } from '@/components/logo';
import { Phone, Mail } from 'lucide-react';

const FOOTER_COLS = [
  {
    title: 'BTS Bank',
    links: [
      { label: 'À propos de la banque', href: '#pourquoi' },
      { label: 'Nos solutions de crédit', href: '#solutions' },
      { label: 'Services numériques', href: '#services' },
      { label: 'Réseau d\'agences', href: '#agences' },
    ],
  },
  {
    title: 'Espace client',
    links: [
      { label: 'Se connecter', href: '/login' },
      { label: 'Créer un compte', href: '/register' },
      { label: 'Suivi de mes demandes', href: '/applications' },
      { label: 'Mon tableau de bord', href: '/dashboard' },
    ],
  },
  {
    title: 'Aide & Support',
    links: [
      { label: 'Foire aux questions (FAQ)', href: '#accueil' },
      { label: 'Trouver une agence', href: '#agences' },
      { label: 'Formulaire de contact', href: '#contact' },
      { label: 'Réclamations', href: '#contact' },
    ],
  },
];

export function LandingFooter() {
  return (
    <footer id="contact" className="border-t-2 border-[#C0272D] bg-[#0C1825] text-white/80 pt-16 pb-8">
      <div className="mx-auto max-w-7xl px-4 sm:px-8 space-y-12">
        <div className="grid grid-cols-2 gap-8 md:grid-cols-4 lg:grid-cols-5">
          {/* Brand Column */}
          <div className="col-span-2 md:col-span-4 lg:col-span-2 space-y-4">
            <Link href="/" className="flex items-center gap-3">
              <Logo size={34} className="brightness-0 invert" />
              <span className="font-display text-lg font-semibold tracking-tight text-white">
                BTS <span className="font-sans font-normal text-xs text-white/50">Bank</span>
              </span>
            </Link>
            <p className="max-w-sm text-xs leading-relaxed text-white/50">
              Banque Tunisienne de Solidarité — Établissement financier public dédié à l&apos;accompagnement des entrepreneurs et au financement des projets en Tunisie.
            </p>
            <div className="flex gap-3 pt-2 text-xs text-white/70">
              <div className="flex items-center gap-1.5 p-2 bg-white/5 rounded border border-white/10">
                <Phone className="size-3.5 text-[#C0272D]" /> (+216) 71 123 456
              </div>
              <div className="flex items-center gap-1.5 p-2 bg-white/5 rounded border border-white/10">
                <Mail className="size-3.5 text-[#C0272D]" /> contact@bts.com.tn
              </div>
            </div>
          </div>

          {/* Link columns */}
          {FOOTER_COLS.map((col) => (
            <div key={col.title}>
              <h4 className="text-xs font-bold uppercase tracking-wider text-white mb-4">{col.title}</h4>
              <ul className="space-y-2 text-xs text-white/60">
                {col.links.map((link) => (
                  <li key={link.label}>
                    <Link
                      href={link.href}
                      className="transition-colors hover:text-white"
                    >
                      {link.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        {/* Bottom bar */}
        <div className="border-t border-white/10 pt-6 flex flex-col sm:flex-row items-center justify-between text-xs text-white/40 gap-4">
          <p>
            © {new Date().getFullYear()} BTS Bank — Banque Tunisienne de Solidarité. Tous droits réservés.
          </p>
          <div className="flex gap-6">
            <Link href="/" className="transition-colors hover:text-white">
              Mentions légales
            </Link>
            <Link href="/" className="transition-colors hover:text-white">
              Politique de confidentialité
            </Link>
            <Link href="/" className="transition-colors hover:text-white">
              Sécurité & RGPD
            </Link>
          </div>
        </div>
      </div>
    </footer>
  );
}
