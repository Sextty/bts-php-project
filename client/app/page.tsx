import Link from 'next/link';
import {
  ArrowRight,
  Briefcase,
  Building2,
  CalendarCheck,
  Check,
  ChevronRight,
  ClipboardList,
  CreditCard,
  FileCheck,
  Globe,
  Landmark,
  Leaf,
  Mail,
  MapPin,
  MessageSquare,
  Monitor,
  Phone,
  Search,
  Shield,
  TrendingUp,
  Upload,
  UserPlus,
  Users,
  Bell,
} from 'lucide-react';
import { Logo } from '@/components/logo';
import { LandingHeader } from '@/components/landing-header';
import { LandingFooter } from '@/components/landing-footer';
import { CreditSimulator } from '@/components/credit-simulator';

/* ═══════════════════════════════════════════════════════════════════════════
   DATA
   ═══════════════════════════════════════════════════════════════════════════ */

const QUICK_ACTIONS = [
  {
    icon: CreditCard,
    title: 'Demander un crédit',
    desc: 'Déposez votre demande en ligne',
    href: '/register',
  },
  {
    icon: Search,
    title: 'Suivre ma demande',
    desc: 'Consultez l\u2019état de votre dossier',
    href: '/applications',
  },
  {
    icon: MapPin,
    title: 'Trouver une agence',
    desc: 'Réseau d\u2019agences régionales',
    href: '#agences',
  },
  {
    icon: Monitor,
    title: 'Mon espace client',
    desc: 'Accédez à votre espace sécurisé',
    href: '/dashboard',
  },
];

const SOLUTIONS = [
  {
    num: '01',
    icon: Briefcase,
    title: 'Crédit Professionnel',
    desc: 'Financement pour le lancement ou l\u2019extension d\u2019une activité artisanale, libérale ou de service.',
    tags: ['Entreprises', 'Artisans', 'Libéraux'],
  },
  {
    num: '02',
    icon: TrendingUp,
    title: 'Crédit d\u2019investissement',
    desc: 'Acquisition de matériels de production, d\u2019équipements et d\u2019aménagements durables.',
    tags: ['Équipements', 'Machines', 'Locaux'],
  },
  {
    num: '03',
    icon: ClipboardList,
    title: 'Crédit de gestion',
    desc: 'Couverture des dépenses d\u2019exploitation pour optimiser le roulement de votre entreprise.',
    tags: ['Trésorerie', 'Exploitation'],
  },
  {
    num: '04',
    icon: Landmark,
    title: 'Fonds de roulement',
    desc: 'Appui à la trésorerie et constitution des stocks initiaux nécessaires au démarrage.',
    tags: ['Stocks', 'Court terme'],
  },
  {
    num: '05',
    icon: Globe,
    title: 'Crédit TIC',
    desc: 'Financement spécifique pour les projets innovants et technologies de l\u2019information.',
    tags: ['Digital', 'Innovation'],
  },
  {
    num: '06',
    icon: Leaf,
    title: 'Finance Islamique',
    desc: 'Formules de financement conformes aux principes de la finance participative (Mourabaha, Ijara).',
    tags: ['Mourabaha', 'Ijara'],
  },
];

const PILLARS = [
  {
    icon: MapPin,
    title: 'Proximité',
    desc: 'Un réseau étendu d\u2019agences réparties sur l\u2019ensemble des gouvernorats tunisiens.',
  },
  {
    icon: Users,
    title: 'Accompagnement',
    desc: 'Conseil technique et assistance personnalisée pour chaque porteur de projet.',
  },
  {
    icon: FileCheck,
    title: 'Simplicité',
    desc: 'Démarches 100% dématérialisées avec dépôt et suivi de dossier en ligne.',
  },
  {
    icon: Shield,
    title: 'Sécurité',
    desc: 'Confidentialité totale et protection des données conformément aux normes bancaires.',
  },
];

const STEPS = [
  {
    num: '01',
    title: 'Créez votre compte',
    desc: 'Inscription rapide avec email, téléphone et vérification par code OTP.',
    icon: UserPlus,
  },
  {
    num: '02',
    title: 'Complétez votre demande',
    desc: 'Renseignez vos coordonnées, le montant du crédit et le descriptif du projet.',
    icon: ClipboardList,
  },
  {
    num: '03',
    title: 'Ajoutez vos documents',
    desc: 'Téléversez vos pièces justificatives (CIN, devis, plan de financement).',
    icon: Upload,
  },
  {
    num: '04',
    title: 'Suivez votre dossier',
    desc: 'Recevez les notifications de validation et confirmez votre rendez-vous en agence.',
    icon: CalendarCheck,
  },
];

const TRACKING_STATUSES = [
  'Demande créée',
  'En cours d\u2019étude',
  'Validation',
  'Rendez-vous',
  'Décision',
];

const DIGITAL_FEATURES = [
  { icon: Monitor, title: 'Espace client sécurisé', desc: 'Gérez votre profil et vos dossiers 24h/24.' },
  { icon: Search, title: 'Suivi des demandes', desc: 'Consultez l\u2019état d\u2019avancement de vos dossiers en temps réel.' },
  { icon: Bell, title: 'Alertes & Notifications', desc: 'Restez informé par SMS et Email à chaque étape clé.' },
  { icon: MessageSquare, title: 'Messagerie Conseiller', desc: 'Échangez directement avec votre agence de rattachement.' },
];

const GOVERNORATES = [
  'Tunis Centre',
  'Ariana',
  'Ben Arous',
  'Sousse',
  'Sfax',
  'Bizerte',
  'Nabeul',
  'Kairouan',
  'Gabès',
  'Médenine',
  'Gafsa',
  'Monastir',
];

/* ═══════════════════════════════════════════════════════════════════════════
   PAGE
   ═══════════════════════════════════════════════════════════════════════════ */

export default function Home() {
  return (
    <div className="min-h-screen bg-white text-[#1E2D3D]">
      <LandingHeader />

      <main id="main">
        {/* ── 1. HERO SECTION ────────────────────────────────────────────── */}
        <section id="accueil" className="relative overflow-hidden bg-gradient-to-b from-white via-white to-[#F4F6F8] pt-6 pb-10 sm:pt-8 sm:pb-14 border-b border-[#E0E4E9]">
          <div className="mx-auto grid max-w-7xl gap-8 lg:gap-12 px-4 sm:px-8 lg:grid-cols-12 lg:items-start">
            {/* Left Column: Copy */}
            <div className="lg:col-span-7 space-y-5">
              <div className="inline-flex items-center gap-2 rounded-full border border-[#FECACA] bg-[#FDF2F2] px-3.5 py-1 text-xs font-semibold text-[#C0272D]">
                <Shield className="size-3.5" />
                Banque Tunisienne de Solidarité
              </div>

              <h1 className="font-display text-4xl sm:text-5xl lg:text-[3.25rem] font-light leading-[1.12] tracking-tight text-[#0C1825]">
                Votre projet mérite <br />
                <span className="font-normal italic text-[#C0272D]">le bon financement.</span>
              </h1>

              <p className="text-base sm:text-lg leading-relaxed text-[#3D5166] max-w-xl">
                Découvrez les solutions de financement de la BTS Bank et suivez votre demande de crédit en ligne, simplement et en toute sécurité.
              </p>

              <div className="flex flex-wrap items-center gap-4 pt-1">
                <Link
                  href="/register"
                  className="btn-red text-sm"
                >
                  Demander un crédit
                  <ArrowRight className="size-4" />
                </Link>
                <Link
                  href="#solutions"
                  className="btn-outline text-sm"
                >
                  Découvrir nos solutions
                </Link>
              </div>

              {/* Institutional Key Points / Trust Bar */}
              <div className="grid grid-cols-3 gap-3 pt-6 border-t border-[#E0E4E9]">
                <div className="space-y-0.5">
                  <div className="text-xs font-bold text-[#0C1825]">100% En Ligne</div>
                  <p className="text-[11px] text-[#3D5166]">Dépôt et suivi dématérialisés</p>
                </div>
                <div className="space-y-0.5">
                  <div className="text-xs font-bold text-[#0C1825]">24 Gouvernorats</div>
                  <p className="text-[11px] text-[#3D5166]">Présence dans toute la Tunisie</p>
                </div>
                <div className="space-y-0.5">
                  <div className="text-xs font-bold text-[#0C1825]">Accompagnement</div>
                  <p className="text-[11px] text-[#3D5166]">Conseillers régionaux dédiés</p>
                </div>
              </div>
            </div>

            {/* Right Column: Interactive Credit Simulator */}
            <div className="lg:col-span-5 hidden lg:block">
              <CreditSimulator />
            </div>
          </div>
        </section>

        {/* ── 2. QUICK ACTIONS BAND ──────────────────────────────────────── */}
        <section className="bg-[#0C1825] border-b-2 border-[#C0272D]">
          <div className="mx-auto max-w-7xl px-4 sm:px-8">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-white/10">
              {QUICK_ACTIONS.map((action, i) => {
                const Icon = action.icon;
                return (
                  <Link
                    key={i}
                    href={action.href}
                    className="p-6 flex items-center justify-between text-white hover:bg-white/5 transition-colors group"
                  >
                    <div>
                      <div className="text-sm font-semibold text-white group-hover:text-[#C0272D] transition-colors">
                        {action.title}
                      </div>
                      <p className="text-xs text-white/50 mt-0.5">{action.desc}</p>
                    </div>
                    <ChevronRight className="size-4 text-white/40 group-hover:text-[#C0272D] group-hover:translate-x-0.5 transition-all" />
                  </Link>
                );
              })}
            </div>
          </div>
        </section>

        {/* ── 3. NOS SOLUTIONS DE FINANCEMENT ────────────────────────────── */}
        <section id="solutions" className="py-20 bg-white border-b border-[#E0E4E9]">
          <div className="mx-auto max-w-7xl px-4 sm:px-8 space-y-12">
            <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 border-b border-[#E0E4E9] pb-6">
              <div>
                <p className="overline mb-2">Nos Catégories de Crédit</p>
                <h2 className="font-display text-3xl sm:text-4xl font-light text-[#0C1825]">
                  Nos solutions de financement
                </h2>
              </div>
              <Link href="/register" className="btn-outline text-xs">
                Voir tous les produits <ArrowRight className="size-3.5" />
              </Link>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              {SOLUTIONS.map((solution) => {
                const Icon = solution.icon;
                return (
                  <div
                    key={solution.title}
                    className="figma-card p-6 flex flex-col justify-between hover:border-[#C0272D] transition-all hover:shadow-md group"
                  >
                    <div className="space-y-3">
                      <div className="flex items-center justify-between">
                        <span className="font-mono text-xs font-bold text-[#C0272D] bg-[#FDF2F2] px-2 py-0.5 rounded">
                          {solution.num}
                        </span>
                        <div className="size-8 rounded-md bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                          <Icon className="size-4" />
                        </div>
                      </div>
                      <h3 className="text-base font-semibold text-[#0C1825] group-hover:text-[#C0272D] transition-colors">
                        {solution.title}
                      </h3>
                      <p className="text-xs text-[#3D5166] leading-relaxed">
                        {solution.desc}
                      </p>
                      <div className="flex flex-wrap gap-1.5 pt-2">
                        {solution.tags.map((t) => (
                          <span key={t} className="text-[10px] font-medium text-[#3D5166] bg-[#F4F6F8] px-2 py-0.5 rounded">
                            {t}
                          </span>
                        ))}
                      </div>
                    </div>

                    <Link
                      href="/register"
                      className="mt-6 inline-flex items-center gap-1.5 text-xs font-semibold text-[#C0272D] hover:underline pt-4 border-t border-[#E0E4E9]"
                    >
                      Déposer une demande <ArrowRight className="size-3" />
                    </Link>
                  </div>
                );
              })}
            </div>
          </div>
        </section>

        {/* ── 4. POURQUOI BTS BANK ───────────────────────────────────────── */}
        <section id="pourquoi" className="py-20 bg-[#F4F6F8] border-b border-[#E0E4E9]">
          <div className="mx-auto max-w-7xl px-4 sm:px-8 space-y-12">
            <div className="max-w-xl">
              <p className="overline mb-2">Valeurs & Engagements</p>
              <h2 className="font-display text-3xl sm:text-4xl font-light text-[#0C1825]">
                Pourquoi choisir BTS Bank ?
              </h2>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
              {PILLARS.map((pillar) => {
                const Icon = pillar.icon;
                return (
                  <div key={pillar.title} className="figma-card p-6 space-y-3 bg-white">
                    <div className="size-10 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                      <Icon className="size-5" />
                    </div>
                    <h3 className="text-base font-semibold text-[#0C1825]">{pillar.title}</h3>
                    <p className="text-xs text-[#3D5166] leading-relaxed">{pillar.desc}</p>
                  </div>
                );
              })}
            </div>
          </div>
        </section>

        {/* ── 5. COMMENT ÇA MARCHE ──────────────────────────────────────── */}
        <section className="py-20 bg-white border-b border-[#E0E4E9]">
          <div className="mx-auto max-w-7xl px-4 sm:px-8 space-y-12">
            <div className="text-center max-w-xl mx-auto">
              <p className="overline justify-center mb-2">Processus en 4 Étapes</p>
              <h2 className="font-display text-3xl sm:text-4xl font-light text-[#0C1825]">
                Votre demande de crédit, simplement en ligne
              </h2>
            </div>

            {/* Desktop: Horizontal Stepper */}
            <div className="hidden md:grid grid-cols-4 gap-6 relative">
              {STEPS.map((step, i) => {
                const Icon = step.icon;
                return (
                  <div key={step.num} className="figma-card p-6 space-y-3 relative">
                    <div className="flex items-center justify-between">
                      <span className="text-2xl font-display font-light text-[#C0272D]">{step.num}</span>
                      <div className="size-8 rounded-full bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
                        <Icon className="size-4" />
                      </div>
                    </div>
                    <h3 className="text-sm font-semibold text-[#0C1825]">{step.title}</h3>
                    <p className="text-xs text-[#3D5166] leading-relaxed">{step.desc}</p>
                    {i < 3 && (
                      <div aria-hidden className="absolute -right-3.5 top-1/2 -translate-y-1/2 text-[#E0E4E9] z-10">
                        <ChevronRight className="size-5" />
                      </div>
                    )}
                  </div>
                );
              })}
            </div>

            {/* Mobile: Vertical List */}
            <div className="md:hidden space-y-4">
              {STEPS.map((step) => (
                <div key={step.num} className="figma-card p-4 space-y-1">
                  <span className="text-xs font-mono font-bold text-[#C0272D]">{step.num}</span>
                  <h3 className="text-sm font-semibold text-[#0C1825]">{step.title}</h3>
                  <p className="text-xs text-[#3D5166]">{step.desc}</p>
                </div>
              ))}
            </div>

            <div className="text-center pt-4">
              <Link href="/register" className="btn-red">
                Commencer ma demande <ArrowRight className="size-4" />
              </Link>
            </div>
          </div>
        </section>

        {/* ── 6. SUIVI DE DOSSIER & SERVICES DIGITAUX ──────────────────── */}
        <section id="services" className="py-20 bg-[#F4F6F8] border-b border-[#E0E4E9]">
          <div className="mx-auto max-w-7xl px-4 sm:px-8 space-y-12">
            <div className="text-center max-w-xl mx-auto">
              <p className="overline justify-center mb-2">Suivi & Services Numériques</p>
              <h2 className="font-display text-3xl sm:text-4xl font-light text-[#0C1825]">
                Une relation bancaire fluide et transparente
              </h2>
            </div>

            {/* Tracking Status Timeline */}
            <div className="figma-card p-6 bg-white max-w-3xl mx-auto space-y-4">
              <div className="text-xs font-bold uppercase tracking-wider text-[#0C1825] text-center">
                Les étapes de validation de votre dossier
              </div>
              <div className="grid grid-cols-5 gap-2 text-center pt-2">
                {TRACKING_STATUSES.map((st, i) => (
                  <div key={st} className="space-y-1.5 flex flex-col items-center">
                    <div className="size-7 rounded-full bg-[#FDF2F2] border border-[#C0272D] text-[#C0272D] text-xs font-bold flex items-center justify-center">
                      {i + 1}
                    </div>
                    <span className="text-[11px] font-medium text-[#3D5166] leading-tight">{st}</span>
                  </div>
                ))}
              </div>
            </div>

            {/* Digital Features */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 pt-4">
              {DIGITAL_FEATURES.map((feat) => {
                const Icon = feat.icon;
                return (
                  <div key={feat.title} className="figma-card p-6 space-y-3 bg-white text-center">
                    <div className="size-10 rounded-lg bg-[#FDF2F2] text-[#C0272D] mx-auto flex items-center justify-center">
                      <Icon className="size-5" />
                    </div>
                    <h3 className="text-sm font-semibold text-[#0C1825]">{feat.title}</h3>
                    <p className="text-xs text-[#3D5166] leading-relaxed">{feat.desc}</p>
                  </div>
                );
              })}
            </div>
          </div>
        </section>

        {/* ── 7. RÉSEAU D'AGENCES ──────────────────────────────────────── */}
        <section id="agences" className="py-20 bg-white border-b border-[#E0E4E9]">
          <div className="mx-auto max-w-7xl px-4 sm:px-8 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
            <div className="lg:col-span-7 space-y-6">
              <p className="overline">Réseau Territorial</p>
              <h2 className="font-display text-3xl sm:text-4xl font-light text-[#0C1825]">
                Une agence BTS près de vous
              </h2>
              <p className="text-sm text-[#3D5166] leading-relaxed">
                Toute demande de crédit déposée en ligne est automatiquement affectée à l&apos;agence BTS Bank compétente dans votre gouvernorat pour instruction et signature des contrats.
              </p>
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-2.5 text-xs font-medium text-[#0C1825] pt-2">
                {GOVERNORATES.map((g) => (
                  <div key={g} className="flex items-center gap-2 p-2.5 bg-[#F4F6F8] rounded-md border border-[#E0E4E9]">
                    <MapPin className="size-3.5 text-[#C0272D] shrink-0" />
                    <span>{g}</span>
                  </div>
                ))}
              </div>
            </div>

            <div className="lg:col-span-5">
              <div className="figma-card p-6 bg-[#F4F6F8] space-y-4">
                <h3 className="text-base font-semibold text-[#0C1825]">Assistance & Agences</h3>
                <p className="text-xs text-[#3D5166]">Nos conseillers régionaux sont à votre disposition.</p>
                <div className="space-y-2.5 pt-2 text-xs">
                  <div className="flex items-center gap-3 p-3 bg-white rounded-md border border-[#E0E4E9]">
                    <Phone className="size-4 text-[#C0272D]" />
                    <span className="font-medium">(+216) 71 123 456</span>
                  </div>
                  <div className="flex items-center gap-3 p-3 bg-white rounded-md border border-[#E0E4E9]">
                    <Mail className="size-4 text-[#C0272D]" />
                    <span className="font-medium">contact@bts.com.tn</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* ── 8. FINAL CTA BANNER ───────────────────────────────────────── */}
        <section className="py-16 bg-[#C0272D] text-white">
          <div className="mx-auto max-w-7xl px-4 sm:px-8 flex flex-col md:flex-row items-center justify-between gap-8">
            <div className="space-y-2">
              <h2 className="font-display text-3xl font-light">Vous avez un projet ?</h2>
              <p className="text-sm text-white/80">Commencez votre demande de crédit en ligne dès aujourd&apos;hui.</p>
            </div>
            <div className="flex flex-wrap gap-4">
              <Link
                href="/register"
                className="btn-ghost-white bg-white text-[#C0272D] hover:bg-white/90 border-white font-semibold"
              >
                Commencer ma demande <ArrowRight className="size-4" />
              </Link>
              <Link
                href="/login"
                className="btn-ghost-white font-medium"
              >
                Se connecter
              </Link>
            </div>
          </div>
        </section>
      </main>

      <LandingFooter />
    </div>
  );
}
