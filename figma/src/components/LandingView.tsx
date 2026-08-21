import { useState } from "react";
import btsLogo from "@/imports/BTS_Banque.png";
import { Ico } from "./Icons";

export function LandingView() {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [activeMenu, setActiveMenu] = useState<string | null>(null);

  const nav = [
    { label: "Accueil", href: "#accueil" },
    {
      label: "Nos crédits",
      href: "#solutions",
      sub: [
        "Crédit Professionnel",
        "Crédit d'investissement",
        "Crédit de gestion",
        "Fonds de roulement",
        "Crédit TIC",
        "Finance Islamique",
      ],
    },
    { label: "Services", href: "#services" },
    { label: "À propos", href: "#pourquoi" },
    { label: "Contact", href: "#contact" },
  ];

  return (
    <div className="bg-white text-[var(--color-ink)] min-h-screen">
      {/* ── 1. Sticky Header ── */}
      <header className="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-[var(--color-rule)]">
        <div className="max-w-7xl mx-auto px-4 sm:px-8 h-18 flex items-center justify-between gap-8">
          {/* Brand */}
          <a href="#accueil" className="flex items-center gap-3 shrink-0">
            <img src={btsLogo} alt="BTS Bank" className="h-10 w-auto object-contain" />
            <span className="font-display text-lg font-semibold text-[var(--color-navy)] hidden sm:inline">
              BTS <span className="font-sans font-normal text-[var(--color-steel)] text-sm">Bank</span>
            </span>
          </a>

          {/* Desktop Nav */}
          <nav className="hidden md:flex items-center gap-1">
            {nav.map((n) => (
              <div
                key={n.label}
                className="relative"
                onMouseEnter={() => n.sub && setActiveMenu(n.label)}
                onMouseLeave={() => setActiveMenu(null)}
              >
                <a
                  href={n.href}
                  className="nav-link flex items-center gap-1 text-sm font-medium text-[var(--color-steel)] hover:text-[var(--color-bts-red)] px-3 py-2 rounded-md transition-colors"
                >
                  {n.label}
                  {n.sub && <Ico.ArrowDown />}
                </a>
                {n.sub && activeMenu === n.label && (
                  <div className="absolute top-full left-0 bg-white border border-[var(--color-rule)] rounded-md shadow-xl p-2 min-w-[220px] z-50 animate-in fade-in zoom-in-95 duration-100">
                    {n.sub.map((s) => (
                      <a
                        key={s}
                        href="#solutions"
                        className="block px-3 py-2 text-xs font-medium text-[var(--color-steel)] hover:text-[var(--color-bts-red)] hover:bg-[var(--color-bts-red-tint)] rounded-sm transition-colors"
                      >
                        {s}
                      </a>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </nav>

          {/* Actions */}
          <div className="hidden md:flex items-center gap-3">
            <a href="#auth" className="btn-outline text-xs" style={{ padding: "8px 18px" }}>
              Se connecter
            </a>
            <a href="#auth" className="btn-red text-xs" style={{ padding: "8px 18px" }}>
              Créer un compte
            </a>
          </div>

          {/* Mobile hamburger */}
          <button
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            className="md:hidden p-2 text-[var(--color-navy)]"
            aria-label="Menu"
          >
            {mobileMenuOpen ? <Ico.Close /> : <Ico.Menu />}
          </button>
        </div>

        {/* Mobile Dropdown */}
        {mobileMenuOpen && (
          <div className="md:hidden border-t border-[var(--color-rule)] bg-white px-6 py-4 space-y-3 shadow-lg">
            {nav.map((n) => (
              <a
                key={n.label}
                href={n.href}
                onClick={() => setMobileMenuOpen(false)}
                className="block py-2 text-sm font-medium text-[var(--color-navy)] border-b border-[var(--color-mist)]"
              >
                {n.label}
              </a>
            ))}
            <div className="pt-2 flex flex-col gap-2">
              <a href="#auth" className="btn-outline justify-center text-xs">Se connecter</a>
              <a href="#auth" className="btn-red justify-center text-xs">Créer un compte</a>
            </div>
          </div>
        )}
      </header>

      {/* ── 2. Hero Section (No Fake Stats) ── */}
      <section id="accueil" className="relative overflow-hidden bg-gradient-to-b from-white to-[var(--color-mist)] py-16 sm:py-24 border-b border-[var(--color-rule)]">
        <div className="max-w-7xl mx-auto px-4 sm:px-8 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
          <div className="lg:col-span-7 space-y-6">
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-[var(--color-bts-red-tint)] border border-[#FECACA] text-xs font-semibold text-[var(--color-bts-red)]">
              <Ico.Shield size={14} /> Banque Tunisienne de Solidarité
            </div>

            <h1 className="font-display text-4xl sm:text-5xl font-light text-[var(--color-navy)] leading-tight tracking-tight">
              Votre projet mérite <br />
              <span className="font-normal italic text-[var(--color-bts-red)]">le bon financement.</span>
            </h1>

            <p className="text-base text-[var(--color-steel)] max-w-xl leading-relaxed">
              Découvrez les solutions de financement de la BTS Bank et suivez votre demande de crédit en ligne, simplement et en toute sécurité.
            </p>

            <div className="flex flex-wrap gap-4 pt-2">
              <a href="#auth" className="btn-red">
                Demander un crédit <Ico.Arrow size={15} />
              </a>
              <a href="#solutions" className="btn-outline">
                Découvrir nos solutions
              </a>
            </div>
          </div>

          {/* Right Visual: Abstract Banking Interface */}
          <div className="lg:col-span-5 hidden lg:block">
            <div className="figma-card p-6 bg-[var(--color-navy)] border-0 text-white rounded-xl shadow-2xl space-y-5 relative overflow-hidden">
              <div className="flex items-center justify-between border-b border-white/10 pb-4">
                <div className="flex items-center gap-2">
                  <div className="w-2.5 h-2.5 rounded-full bg-[var(--color-bts-red)]"></div>
                  <span className="text-xs font-semibold tracking-wider uppercase text-white/80">Espace Crédit BTS</span>
                </div>
                <span className="text-[10px] font-mono text-white/40">Portail Client v2.0</span>
              </div>

              <div className="space-y-3">
                <div className="p-3 bg-white/5 rounded border border-white/10 flex items-center justify-between">
                  <div>
                    <div className="text-[11px] text-white/50">Dossier en cours</div>
                    <div className="text-xs font-semibold text-white">Crédit Professionnel</div>
                  </div>
                  <span className="badge badge-warning text-[10px]">En cours d&apos;étude</span>
                </div>

                <div className="p-3 bg-white/5 rounded border border-white/10 space-y-2">
                  <div className="flex justify-between text-[11px] text-white/60">
                    <span>Avancement du dossier</span>
                    <span className="text-[var(--color-bts-red)] font-semibold">Étape 3/4</span>
                  </div>
                  <div className="w-full h-1.5 bg-white/10 rounded-full overflow-hidden">
                    <div className="h-full bg-[var(--color-bts-red)] w-3/4 rounded-full"></div>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-2 text-[11px]">
                  <div className="p-2.5 bg-white/5 rounded border border-white/10 flex items-center gap-2">
                    <Ico.Cal size={14} className="text-[var(--color-bts-red)]" />
                    <div>
                      <div className="text-[9px] text-white/40 uppercase">Rendez-vous</div>
                      <div className="text-white/80 font-medium">Agence Affectée</div>
                    </div>
                  </div>
                  <div className="p-2.5 bg-white/5 rounded border border-white/10 flex items-center gap-2">
                    <Ico.Shield size={14} className="text-emerald-400" />
                    <div>
                      <div className="text-[9px] text-white/40 uppercase">Sécurité</div>
                      <div className="text-white/80 font-medium">100% Vérifié</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ── 3. Quick Actions ── */}
      <section className="bg-[var(--color-navy)] py-0 border-b-2 border-[var(--color-bts-red)]">
        <div className="max-w-7xl mx-auto px-4 sm:px-8">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-white/10">
            {[
              { title: "Demander un crédit", desc: "Déposez votre demande en ligne", icon: <Ico.File /> },
              { title: "Suivre ma demande", desc: "Consultez l'état de votre dossier", icon: <Ico.Arrow /> },
              { title: "Trouver mon agence", desc: "Réseau d'agences régionales", icon: <Ico.Pin /> },
              { title: "Mon espace client", desc: "Accédez à votre espace sécurisé", icon: <Ico.User /> },
            ].map((q, i) => (
              <a
                key={i}
                href="#auth"
                className="p-6 flex items-center justify-between text-white hover:bg-white/5 transition-colors group"
              >
                <div>
                  <div className="text-sm font-semibold text-white group-hover:text-[var(--color-bts-red)] transition-colors">
                    {q.title}
                  </div>
                  <div className="text-xs text-white/50 mt-0.5">{q.desc}</div>
                </div>
                <div className="text-white/40 group-hover:text-[var(--color-bts-red)] transition-colors">
                  <Ico.Arrow size={14} />
                </div>
              </a>
            ))}
          </div>
        </div>
      </section>

      {/* ── 4. Financing Solutions ── */}
      <section id="solutions" className="py-20 bg-white border-b border-[var(--color-rule)]">
        <div className="max-w-7xl mx-auto px-4 sm:px-8 space-y-12">
          <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 border-b border-[var(--color-rule)] pb-6">
            <div>
              <p className="overline mb-2">Nos Catégories de Crédit</p>
              <h2 className="font-display text-3xl font-light text-[var(--color-navy)]">
                Nos solutions de financement
              </h2>
            </div>
            <a href="#auth" className="btn-outline text-xs">Voir tous les produits <Ico.Arrow size={13} /></a>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {[
              { num: "01", title: "Crédit Professionnel", desc: "Financement pour le lancement ou l'extension d'une activité artisanale, libérale ou de service." },
              { num: "02", title: "Crédit d'investissement", desc: "Acquisition de matériels de production, d'équipements et d'aménagements durables." },
              { num: "03", title: "Crédit de gestion", desc: "Couverture des dépenses d'exploitation pour optimiser le roulement de votre entreprise." },
              { num: "04", title: "Fonds de roulement", desc: "Appui à la trésorerie et constitution des stocks initiaux nécessaires au démarrage." },
              { num: "05", title: "Crédit TIC", desc: "Financement spécifique pour les projets innovants et technologies de l'information." },
              { num: "06", title: "Finance Islamique", desc: "Formules de financement conformes aux principes de la finance participative (Mourabaha, Ijara)." },
            ].map((s) => (
              <div key={s.title} className="figma-card p-6 flex flex-col justify-between hover:border-[var(--color-bts-red)] transition-colors">
                <div className="space-y-3">
                  <span className="text-xs font-mono font-bold text-[var(--color-bts-red)]">{s.num}</span>
                  <h3 className="text-base font-semibold text-[var(--color-navy)]">{s.title}</h3>
                  <p className="text-xs text-[var(--color-steel)] leading-relaxed">{s.desc}</p>
                </div>
                <a href="#auth" className="mt-6 inline-flex items-center gap-1.5 text-xs font-semibold text-[var(--color-bts-red)] hover:underline">
                  En savoir plus <Ico.Arrow size={12} />
                </a>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── 5. Why BTS Bank ── */}
      <section id="pourquoi" className="py-20 bg-[var(--color-mist)] border-b border-[var(--color-rule)]">
        <div className="max-w-7xl mx-auto px-4 sm:px-8 space-y-12">
          <div className="max-w-xl">
            <p className="overline mb-2">Valeurs & Engagements</p>
            <h2 className="font-display text-3xl font-light text-[var(--color-navy)]">
              Pourquoi choisir BTS Bank ?
            </h2>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            {[
              { title: "Proximité", desc: "Un réseau étendu d'agences réparties sur l'ensemble des gouvernorats tunisiens.", icon: <Ico.Pin /> },
              { title: "Accompagnement", desc: "Conseil technique et assistance personnalisée pour chaque porteur de projet.", icon: <Ico.User /> },
              { title: "Simplicité", desc: "Démarches 100% dématérialisées avec dépôt et suivi de dossier en ligne.", icon: <Ico.File /> },
              { title: "Sécurité", desc: "Confidentialité totale et protection des données conformément aux normes bancaires.", icon: <Ico.Shield /> },
            ].map((p) => (
              <div key={p.title} className="figma-card p-6 space-y-3">
                <div className="size-10 rounded-md bg-[var(--color-bts-red-tint)] text-[var(--color-bts-red)] flex items-center justify-center">
                  {p.icon}
                </div>
                <h3 className="text-base font-semibold text-[var(--color-navy)]">{p.title}</h3>
                <p className="text-xs text-[var(--color-steel)] leading-relaxed">{p.desc}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── 6. Comment ça marche (Timeline Stepper) ── */}
      <section className="py-20 bg-white border-b border-[var(--color-rule)]">
        <div className="max-w-7xl mx-auto px-4 sm:px-8 space-y-12">
          <div className="text-center max-w-xl mx-auto">
            <p className="overline justify-center mb-2">Processus en 4 Étapes</p>
            <h2 className="font-display text-3xl font-light text-[var(--color-navy)]">
              Votre demande de crédit, simplement en ligne
            </h2>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-4 gap-6 relative">
            {[
              { step: "01", title: "Créez votre compte", desc: "Inscription rapide avec email, téléphone et vérification par code OTP." },
              { step: "02", title: "Complétez votre demande", desc: "Renseignez vos coordonnées, le montant du crédit et le descriptif du projet." },
              { step: "03", title: "Ajoutez vos documents", desc: "Téléversez vos pièces justificatives (CIN, devis, plan de financement)." },
              { step: "04", title: "Suivez votre dossier", desc: "Recevez les notifications de validation et confirmez votre rendez-vous en agence." },
            ].map((st, i) => (
              <div key={st.step} className="figma-card p-6 space-y-3 relative">
                <div className="text-2xl font-display font-light text-[var(--color-bts-red)]">
                  {st.step}
                </div>
                <h3 className="text-sm font-semibold text-[var(--color-navy)]">{st.title}</h3>
                <p className="text-xs text-[var(--color-steel)] leading-relaxed">{st.desc}</p>
                {i < 3 && (
                  <div className="hidden md:block absolute -right-3 top-1/2 -translate-y-1/2 text-[var(--color-rule)] z-10">
                    <Ico.Arrow size={16} />
                  </div>
                )}
              </div>
            ))}
          </div>

          <div className="text-center pt-4">
            <a href="#auth" className="btn-red">
              Commencer ma demande <Ico.Arrow size={15} />
            </a>
          </div>
        </div>
      </section>

      {/* ── 7. Agency Network & Contact ── */}
      <section className="py-20 bg-[var(--color-mist)] border-b border-[var(--color-rule)]">
        <div className="max-w-7xl mx-auto px-4 sm:px-8 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
          <div className="lg:col-span-7 space-y-6">
            <p className="overline">Réseau Territorial</p>
            <h2 className="font-display text-3xl font-light text-[var(--color-navy)]">
              Une agence BTS près de vous
            </h2>
            <p className="text-sm text-[var(--color-steel)] leading-relaxed">
              Toute demande de crédit déposée en ligne est automatiquement orientée vers l&apos;agence BTS Bank compétente dans votre gouvernorat pour instruction et rendez-vous physique.
            </p>
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 text-xs font-medium text-[var(--color-navy)] pt-2">
              {["Tunis Centre", "Ariana", "Ben Arous", "Sousse", "Sfax", "Bizerte", "Nabeul", "Kairouan", "Gabès", "Médenine", "Gafsa", "Monastir"].map((c) => (
                <div key={c} className="flex items-center gap-1.5 p-2 bg-white rounded border border-[var(--color-rule)]">
                  <Ico.Pin size={13} className="text-[var(--color-bts-red)] shrink-0" />
                  <span>{c}</span>
                </div>
              ))}
            </div>
          </div>

          <div className="lg:col-span-5">
            <div className="figma-card p-6 bg-white space-y-4">
              <h3 className="text-base font-semibold text-[var(--color-navy)]">Centre de Contact BTS</h3>
              <p className="text-xs text-[var(--color-steel)]">Nos conseillers clients sont à votre écoute pour vous orienter.</p>
              <div className="space-y-2.5 pt-2 text-xs">
                <div className="flex items-center gap-3 p-2.5 bg-[var(--color-mist)] rounded">
                  <Ico.Phone size={16} className="text-[var(--color-bts-red)]" />
                  <span>(+216) 71 123 456</span>
                </div>
                <div className="flex items-center gap-3 p-2.5 bg-[var(--color-mist)] rounded">
                  <Ico.Mail size={16} className="text-[var(--color-bts-red)]" />
                  <span>contact@bts.com.tn</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ── 8. Final CTA ── */}
      <section className="py-16 bg-[var(--color-bts-red)] text-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-8 flex flex-col md:flex-row items-center justify-between gap-8">
          <div className="space-y-2">
            <h2 className="font-display text-3xl font-light">Vous avez un projet ?</h2>
            <p className="text-sm text-white/80">Commencez votre demande de financement en ligne en quelques minutes.</p>
          </div>
          <div className="flex gap-4">
            <a href="#auth" className="btn-ghost-white bg-white text-[var(--color-bts-red)] hover:bg-white/90 border-white">
              Demander un crédit
            </a>
            <a href="#auth" className="btn-ghost-white">
              Se connecter
            </a>
          </div>
        </div>
      </section>

      {/* ── 9. Institutional Footer ── */}
      <footer id="contact" className="bg-[var(--color-navy)] text-white/80 pt-16 pb-8 border-t-2 border-[var(--color-bts-red)]">
        <div className="max-w-7xl mx-auto px-4 sm:px-8 space-y-12">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-8">
            <div>
              <h4 className="text-xs font-bold uppercase tracking-wider text-white mb-4">BTS Bank</h4>
              <ul className="space-y-2 text-xs text-white/60">
                <li><a href="#" className="hover:text-white">À propos de la banque</a></li>
                <li><a href="#" className="hover:text-white">Nos solutions de crédit</a></li>
                <li><a href="#" className="hover:text-white">Services numériques</a></li>
                <li><a href="#" className="hover:text-white">Réseau d&apos;agences</a></li>
              </ul>
            </div>
            <div>
              <h4 className="text-xs font-bold uppercase tracking-wider text-white mb-4">Espace Client</h4>
              <ul className="space-y-2 text-xs text-white/60">
                <li><a href="#auth" className="hover:text-white">Se connecter</a></li>
                <li><a href="#auth" className="hover:text-white">Créer un compte</a></li>
                <li><a href="#dashboard" className="hover:text-white">Suivi de mes demandes</a></li>
                <li><a href="#dashboard" className="hover:text-white">Mes rendez-vous</a></li>
              </ul>
            </div>
            <div>
              <h4 className="text-xs font-bold uppercase tracking-wider text-white mb-4">Aide & Support</h4>
              <ul className="space-y-2 text-xs text-white/60">
                <li><a href="#" className="hover:text-white">Foire aux questions (FAQ)</a></li>
                <li><a href="#" className="hover:text-white">Formulaire de contact</a></li>
                <li><a href="#" className="hover:text-white">Réclamations</a></li>
              </ul>
            </div>
            <div>
              <h4 className="text-xs font-bold uppercase tracking-wider text-white mb-4">Informations Légales</h4>
              <p className="text-xs text-white/50 leading-relaxed">
                Banque Tunisienne de Solidarité — Établissement financier public agréé par la Banque Centrale de Tunisie.
              </p>
            </div>
          </div>

          <div className="border-t border-white/10 pt-6 flex flex-col sm:flex-row items-center justify-between text-xs text-white/40 gap-4">
            <div>© 2026 BTS Bank. Tous droits réservés.</div>
            <div className="flex gap-6">
              <a href="#" className="hover:text-white">Mentions légales</a>
              <a href="#" className="hover:text-white">Politique de confidentialité</a>
              <a href="#" className="hover:text-white">Sécurité</a>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
}
