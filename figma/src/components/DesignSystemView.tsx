import { useState } from "react";
import { Ico } from "./Icons";

export function DesignSystemView() {
  const [showPassword, setShowPassword] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);

  return (
    <div className="space-y-12 pb-20">
      {/* ── Header ── */}
      <div className="border-b border-[var(--color-rule)] pb-6">
        <p className="overline mb-2">Design System BTS Bank</p>
        <h1 className="font-display text-3xl font-normal text-[var(--color-navy)]">
          Guide des Styles & Composants Institutionnels
        </h1>
        <p className="mt-2 text-sm text-[var(--color-steel)] max-w-2xl">
          Système de conception unifié pour la plateforme digitale BTS Bank. Respect strict des règles d&apos;identité visuelle, accessibilité WCAG, et intégration des données dynamiques réelles sans valeurs fictives.
        </p>
      </div>

      {/* ── 1. Color Palette ── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold text-[var(--color-navy)] flex items-center gap-2">
          <span className="w-2 h-2 rounded-full bg-[var(--color-bts-red)]"></span>
          1. Palette de Couleurs Officielles
        </h2>
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-4">
          {[
            { name: "Primary BTS Red", hex: "#C0272D", token: "--color-bts-red", text: "#fff" },
            { name: "Primary Red Dark", hex: "#9E1F24", token: "--color-bts-red-dark", text: "#fff" },
            { name: "Red Tint", hex: "#FDF2F2", token: "--color-bts-red-tint", text: "var(--color-bts-red)" },
            { name: "Deep Navy", hex: "#0C1825", token: "--color-navy", text: "#fff" },
            { name: "Navy Mid", hex: "#172436", token: "--color-navy-mid", text: "#fff" },
            { name: "Dark Text (Ink)", hex: "#1E2D3D", token: "--color-ink", text: "#fff" },
            { name: "Secondary Text (Steel)", hex: "#3D5166", token: "--color-steel", text: "#fff" },
            { name: "Light Mist", hex: "#F4F6F8", token: "--color-mist", text: "var(--color-ink)" },
            { name: "Border Rule", hex: "#E0E4E9", token: "--color-rule", text: "var(--color-ink)" },
            { name: "Success Green", hex: "#065F46", token: "--color-success", text: "#fff" },
            { name: "Warning Amber", hex: "#92400E", token: "--color-warning", text: "#fff" },
            { name: "Error Red", hex: "#B91C1C", token: "--color-error", text: "#fff" },
            { name: "Info Blue", hex: "#1E40AF", token: "--color-info", text: "#fff" },
          ].map((c) => (
            <div key={c.name} className="figma-card p-3 space-y-2">
              <div
                className="h-16 rounded-md flex items-center justify-center font-mono text-xs font-semibold shadow-inner"
                style={{ background: c.hex, color: c.text }}
              >
                {c.hex}
              </div>
              <div>
                <div className="text-xs font-semibold text-[var(--color-navy)]">{c.name}</div>
                <div className="text-[10px] font-mono text-[var(--color-steel)]">{c.token}</div>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* ── 2. Typography ── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold text-[var(--color-navy)] flex items-center gap-2">
          <span className="w-2 h-2 rounded-full bg-[var(--color-bts-red)]"></span>
          2. Échelle Typographique
        </h2>
        <div className="figma-card divide-y divide-[var(--color-rule)]">
          {[
            { label: "Display Title", style: "font-display text-4xl font-light", spec: "Fraunces 36px / Light (300)" },
            { label: "Heading 1 (H1)", style: "font-display text-2xl font-normal", spec: "Fraunces 24px / Regular (400)" },
            { label: "Heading 2 (H2)", style: "text-xl font-semibold", spec: "Inter 20px / SemiBold (600)" },
            { label: "Heading 3 (H3)", style: "text-base font-semibold", spec: "Inter 16px / SemiBold (600)" },
            { label: "Body Large", style: "text-base font-normal text-[var(--color-steel)]", spec: "Inter 16px / Regular (400)" },
            { label: "Body Standard", style: "text-sm font-normal text-[var(--color-steel)]", spec: "Inter 14px / Regular (400)" },
            { label: "Body Small / Caption", style: "text-xs font-normal text-[var(--color-steel)]", spec: "Inter 12px / Regular (400)" },
            { label: "Overline Label", style: "overline", spec: "Inter 11px / Bold (700) Uppercase +2px Rule" },
          ].map((t) => (
            <div key={t.label} className="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
              <div className={t.style}>{t.label} — BTS Bank Tunisie</div>
              <span className="text-xs font-mono text-[var(--color-steel)] shrink-0">{t.spec}</span>
            </div>
          ))}
        </div>
      </section>

      {/* ── 3. Buttons & Actions ── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold text-[var(--color-navy)] flex items-center gap-2">
          <span className="w-2 h-2 rounded-full bg-[var(--color-bts-red)]"></span>
          3. Boutons & Actions
        </h2>
        <div className="figma-card p-6 space-y-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 items-center">
            <div>
              <div className="text-xs font-semibold text-[var(--color-steel)] mb-2">Bouton Principal (Red)</div>
              <button className="btn-red w-full">
                Demander un crédit <Ico.Arrow size={15} />
              </button>
            </div>
            <div>
              <div className="text-xs font-semibold text-[var(--color-steel)] mb-2">Bouton Secondaire (Outline)</div>
              <button className="btn-outline w-full">
                Découvrir nos solutions
              </button>
            </div>
            <div>
              <div className="text-xs font-semibold text-[var(--color-steel)] mb-2">Bouton Désactivé</div>
              <button className="btn-red w-full" disabled>
                Action désactivée
              </button>
            </div>
            <div>
              <div className="text-xs font-semibold text-[var(--color-steel)] mb-2">Bouton Chargement</div>
              <button className="btn-red w-full" disabled>
                <Ico.Refresh size={14} className="animate-spin" /> Traitement en cours…
              </button>
            </div>
          </div>

          <div className="border-t border-[var(--color-rule)] pt-4">
            <div className="text-xs font-semibold text-[var(--color-steel)] mb-2">Bouton Google Authentification (Conforme Google GIS)</div>
            <div className="max-w-sm">
              <button className="btn-google">
                <Ico.Google size={18} />
                Continuer avec Google
              </button>
            </div>
          </div>
        </div>
      </section>

      {/* ── 4. Form Inputs & Controls ── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold text-[var(--color-navy)] flex items-center gap-2">
          <span className="w-2 h-2 rounded-full bg-[var(--color-bts-red)]"></span>
          4. Champs de Saisie & Contrôles
        </h2>
        <div className="figma-card p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1.5">
              Adresse Email <span className="text-[var(--color-bts-red)]">*</span>
            </label>
            <input type="email" placeholder="nom@exemple.tn" className="input-text" />
          </div>

          <div>
            <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1.5">
              Mot de passe sécurisé <span className="text-[var(--color-bts-red)]">*</span>
            </label>
            <div className="relative">
              <input
                type={showPassword ? "text" : "password"}
                defaultValue="••••••••••••"
                className="input-text pr-10"
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--color-steel)] hover:text-[var(--color-navy)]"
              >
                {showPassword ? <Ico.EyeOff size={16} /> : <Ico.Eye size={16} />}
              </button>
            </div>
          </div>

          <div>
            <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1.5">
              Champ Verrouillé / Protégé (Lecture seule API)
            </label>
            <div className="relative">
              <input
                type="text"
                value="PRJ-2026-TUN-08492"
                readOnly
                className="input-text readonly pr-8"
              />
              <span className="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--color-steel)]">
                <Ico.Lock size={14} />
              </span>
            </div>
          </div>

          <div>
            <label className="block text-xs font-semibold text-[var(--color-error)] mb-1.5">
              Champ avec Erreur de Validation
            </label>
            <input
              type="text"
              defaultValue="Numéro invalide"
              className="input-text border-[var(--color-error)] focus:border-[var(--color-error)] focus:ring-[var(--color-error-bg)]"
            />
            <p className="mt-1 text-xs text-[var(--color-error)] flex items-center gap-1">
              <Ico.AlertCircle size={12} /> Format du numéro de téléphone tunisien incorrect (+216)
            </p>
          </div>
        </div>
      </section>

      {/* ── 5. Status Badges & Alerts ── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold text-[var(--color-navy)] flex items-center gap-2">
          <span className="w-2 h-2 rounded-full bg-[var(--color-bts-red)]"></span>
          5. Badges de Statut & Alertes Métier
        </h2>
        <div className="figma-card p-6 space-y-6">
          <div>
            <div className="text-xs font-semibold text-[var(--color-steel)] mb-3">Statuts Officiels de Demande de Crédit</div>
            <div className="flex flex-wrap gap-2">
              <span className="badge badge-neutral">DRAFT (Brouillon)</span>
              <span className="badge badge-warning">En cours d&apos;étude</span>
              <span className="badge badge-info">Validation 1 réussie</span>
              <span className="badge badge-warning">Validation 2 en cours</span>
              <span className="badge badge-info">FINAL_LOCKED</span>
              <span className="badge badge-info">SUBMITTED (Soumise)</span>
              <span className="badge badge-success">STAFF_APPROVED</span>
              <span className="badge badge-success">APPROVED (Approuvée)</span>
              <span className="badge badge-red">REJECTED (Rejetée)</span>
              <span className="badge badge-info">Rendez-vous proposé</span>
              <span className="badge badge-success">Rendez-vous confirmé</span>
              <span className="badge badge-neutral">Annulée</span>
            </div>
          </div>

          <div className="border-t border-[var(--color-rule)] pt-4 space-y-3">
            <div className="text-xs font-semibold text-[var(--color-steel)] mb-2">Bannières d&apos;Alerte & Notifications</div>
            
            <div className="p-3.5 bg-[var(--color-error-bg)] border border-[#FECACA] rounded-md text-xs text-[var(--color-error)] flex items-start gap-2.5">
              <Ico.AlertCircle size={16} className="shrink-0 mt-0.5" />
              <div>
                <strong>Erreur d&apos;authentification :</strong> Identifiant ou mot de passe incorrect. Veuillez vérifier vos accès.
              </div>
            </div>

            <div className="p-3.5 bg-[var(--color-warning-bg)] border border-[#FDE68A] rounded-md text-xs text-[var(--color-warning)] flex items-start gap-2.5">
              <Ico.Info size={16} className="shrink-0 mt-0.5" />
              <div>
                <strong>Action requise :</strong> Au moins une catégorie de financement (FDR, AMG, CHP ou EPR) doit être complétée avec son document justificatif.
              </div>
            </div>

            <div className="p-3.5 bg-[var(--color-success-bg)] border border-[#A7F3D0] rounded-md text-xs text-[var(--color-success)] flex items-start gap-2.5">
              <Ico.Check size={16} className="shrink-0 mt-0.5" />
              <div>
                <strong>Dossier soumis avec succès :</strong> Votre demande est enregistrée et transmise à votre agence BTS Bank locale.
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ── 6. Modals & Dialogs ── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold text-[var(--color-navy)] flex items-center gap-2">
          <span className="w-2 h-2 rounded-full bg-[var(--color-bts-red)]"></span>
          6. Modale de Confirmation & États de Dialogue
        </h2>
        <div className="figma-card p-6">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-sm font-semibold text-[var(--color-navy)]">Modale d&apos;Annulation de Rendez-vous</div>
              <div className="text-xs text-[var(--color-steel)]">Dialogue de confirmation sécurisé avec passage au statut annulé et ouverture du chat conseiller.</div>
            </div>
            <button onClick={() => setModalOpen(true)} className="btn-outline">
              Ouvrir la modale
            </button>
          </div>

          {modalOpen && (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-xs p-4">
              <div className="bg-white rounded-lg border border-[var(--color-rule)] shadow-2xl max-w-md w-full p-6 space-y-4 animate-in fade-in zoom-in duration-150">
                <div className="flex items-center gap-3 text-[var(--color-bts-red)]">
                  <div className="size-10 rounded-full bg-[var(--color-bts-red-tint)] flex items-center justify-center">
                    <Ico.AlertCircle size={20} />
                  </div>
                  <div>
                    <h3 className="text-base font-semibold text-[var(--color-navy)]">Annuler le rendez-vous ?</h3>
                    <p className="text-xs text-[var(--color-steel)]">Cette action modifiera le statut de votre dossier.</p>
                  </div>
                </div>
                <p className="text-xs text-[var(--color-steel)] leading-relaxed">
                  Êtes-vous sûr de vouloir annuler ce rendez-vous en agence ? Un canal de discussion direct avec votre conseiller sera immédiatement ouvert pour reprogrammer une date.
                </p>
                <div className="flex justify-end gap-3 pt-2">
                  <button onClick={() => setModalOpen(false)} className="btn-outline" style={{ padding: "8px 18px" }}>
                    Retour
                  </button>
                  <button onClick={() => setModalOpen(false)} className="btn-red" style={{ padding: "8px 18px" }}>
                    Confirmer l&apos;annulation
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      </section>

      {/* ── 7. Data-Driven Placeholders Matrix ── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold text-[var(--color-navy)] flex items-center gap-2">
          <span className="w-2 h-2 rounded-full bg-[var(--color-bts-red)]"></span>
          7. Référentiel des Variables Dynamiques API (Zero Fake Data)
        </h2>
        <div className="figma-card p-6 space-y-3">
          <p className="text-xs text-[var(--color-steel)]">
            Conformément à la règle de conception stricte, aucune donnée financière, solde ou statistique fictive n&apos;est injectée. Les champs dynamiques utilisent la syntaxe normalisée ci-dessous :
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
            {[
              { tag: "{{balance}}", desc: "Solde bancaire client (ou 'Solde non disponible')" },
              { tag: "{{clientName}}", desc: "Prénom & Nom du client connecté" },
              { tag: "{{applicationStatus}}", desc: "Statut officiel du dossier de crédit" },
              { tag: "{{applicationId}}", desc: "Numéro de référence du dossier" },
              { tag: "{{projectCode}}", desc: "Code de projet système 🔒" },
              { tag: "{{personId}}", desc: "Identifiant personne système 🔒" },
              { tag: "{{agencyName}}", desc: "Nom officiel de l'agence BTS affectée" },
              { tag: "{{appointmentDate}}", desc: "Date du rendez-vous en agence" },
              { tag: "{{unreadNotifications}}", desc: "Nombre d'alertes non lues" },
            ].map((p) => (
              <div key={p.tag} className="p-2.5 bg-[var(--color-mist)] border border-[var(--color-rule)] rounded-md">
                <span className="placeholder-tag">{p.tag}</span>
                <p className="text-[11px] text-[var(--color-steel)] mt-1">{p.desc}</p>
              </div>
            ))}
          </div>
        </div>
      </section>
    </div>
  );
}
