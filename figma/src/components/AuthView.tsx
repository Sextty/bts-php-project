import { useState } from "react";
import btsLogo from "@/imports/BTS_Banque.png";
import { Ico } from "./Icons";

type AuthTab = "login" | "register" | "otp";
type AuthState = "normal" | "loading" | "error_credentials" | "error_server";

export function AuthView() {
  const [activeTab, setActiveTab] = useState<AuthTab>("login");
  const [authState, setAuthState] = useState<AuthState>("normal");
  const [showPassword, setShowPassword] = useState(false);

  return (
    <div className="space-y-8 pb-20 max-w-4xl mx-auto">
      {/* ── State & Screen Switcher Bar (Figma Inspector Tool) ── */}
      <div className="figma-card p-4 bg-white border-l-4 border-l-[var(--color-bts-red)] flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-2">
          <span className="text-xs font-bold uppercase text-[var(--color-navy)]">Écran :</span>
          {(["login", "register", "otp"] as AuthTab[]).map((tab) => (
            <button
              key={tab}
              onClick={() => setActiveTab(tab)}
              className={`px-3 py-1.5 rounded text-xs font-semibold transition-colors ${
                activeTab === tab
                  ? "bg-[var(--color-bts-red)] text-white"
                  : "bg-[var(--color-mist)] text-[var(--color-steel)] hover:bg-gray-200"
              }`}
            >
              {tab === "login" && "1. Connexion"}
              {tab === "register" && "2. Inscription"}
              {tab === "otp" && "3. Vérification OTP"}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-2">
          <span className="text-xs font-bold uppercase text-[var(--color-navy)]">État UX :</span>
          {(["normal", "loading", "error_credentials", "error_server"] as AuthState[]).map((st) => (
            <button
              key={st}
              onClick={() => setAuthState(st)}
              className={`px-2.5 py-1 rounded text-[11px] font-medium transition-colors ${
                authState === st
                  ? "bg-[var(--color-navy)] text-white"
                  : "bg-[var(--color-mist)] text-[var(--color-steel)] hover:bg-gray-200"
              }`}
            >
              {st === "normal" && "Normal"}
              {st === "loading" && "Chargement"}
              {st === "error_credentials" && "Identifiants Invalides"}
              {st === "error_server" && "Erreur Serveur"}
            </button>
          ))}
        </div>
      </div>

      {/* ── Main Auth Card Frame ── */}
      <div className="max-w-md mx-auto figma-card p-8 bg-white shadow-xl space-y-6">
        {/* Logo & Header */}
        <div className="text-center space-y-3">
          <img src={btsLogo} alt="BTS Bank" className="h-12 w-auto mx-auto object-contain" />
          <h2 className="font-display text-2xl font-normal text-[var(--color-navy)]">
            {activeTab === "login" && "Connexion à votre espace client"}
            {activeTab === "register" && "Créer un compte BTS Bank"}
            {activeTab === "otp" && "Vérification de sécurité"}
          </h2>
          <p className="text-xs text-[var(--color-steel)]">
            {activeTab === "login" && "Accédez à vos demandes de crédit et vos services bancaires"}
            {activeTab === "register" && "Renseignez vos coordonnées pour démarrer votre demande"}
            {activeTab === "otp" && "Saisissez le code à 6 chiffres transmis par SMS / Email"}
          </p>
        </div>

        {/* ── Error Banners according to state ── */}
        {authState === "error_credentials" && (
          <div className="p-3 bg-[var(--color-error-bg)] border border-[#FECACA] rounded-md text-xs text-[var(--color-error)] flex items-start gap-2 animate-in fade-in duration-200">
            <Ico.AlertCircle size={15} className="shrink-0 mt-0.5" />
            <div>
              <strong>Identifiants incorrects :</strong> L&apos;adresse email ou le mot de passe est invalide.
            </div>
          </div>
        )}

        {authState === "error_server" && (
          <div className="p-3 bg-[var(--color-error-bg)] border border-[#FECACA] rounded-md text-xs text-[var(--color-error)] flex items-start gap-2 animate-in fade-in duration-200">
            <Ico.AlertCircle size={15} className="shrink-0 mt-0.5" />
            <div>
              <strong>Erreur serveur :</strong> Impossible de joindre le serveur BTS. Veuillez réessayer dans un instant.
            </div>
          </div>
        )}

        {/* ── Form: Login ── */}
        {activeTab === "login" && (
          <form className="space-y-4" onSubmit={(e) => e.preventDefault()}>
            <div>
              <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">
                Email ou Identifiant
              </label>
              <input
                type="email"
                placeholder="nom@exemple.tn"
                defaultValue={authState === "error_credentials" ? "client.invalide@bts.tn" : ""}
                disabled={authState === "loading"}
                className="input-text"
              />
            </div>

            <div>
              <div className="flex justify-between items-center mb-1">
                <label className="text-xs font-semibold text-[var(--color-navy)]">
                  Mot de passe
                </label>
                <a href="#" className="text-xs text-[var(--color-bts-red)] hover:underline">
                  Mot de passe oublié ?
                </a>
              </div>
              <div className="relative">
                <input
                  type={showPassword ? "text" : "password"}
                  placeholder="••••••••••••"
                  defaultValue={authState === "error_credentials" ? "motdepasse" : ""}
                  disabled={authState === "loading"}
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

            <button type="submit" disabled={authState === "loading"} className="btn-red w-full">
              {authState === "loading" ? (
                <>
                  <Ico.Refresh size={15} className="animate-spin" /> Connexion en cours…
                </>
              ) : (
                <>Se connecter</>
              )}
            </button>

            <div className="relative my-4">
              <div className="absolute inset-0 flex items-center">
                <div className="w-full border-t border-[var(--color-rule)]"></div>
              </div>
              <div className="relative flex justify-center text-xs uppercase">
                <span className="bg-white px-3 text-[var(--color-steel)] font-semibold">ou</span>
              </div>
            </div>

            {/* Google Single Button */}
            <button
              type="button"
              disabled={authState === "loading"}
              className="btn-google"
            >
              <Ico.Google size={18} />
              Continuer avec Google
            </button>

            <div className="text-center pt-2 text-xs text-[var(--color-steel)]">
              Vous n&apos;avez pas encore de compte ?{" "}
              <button
                type="button"
                onClick={() => setActiveTab("register")}
                className="font-semibold text-[var(--color-bts-red)] hover:underline"
              >
                Créer un compte
              </button>
            </div>
          </form>
        )}

        {/* ── Form: Register ── */}
        {activeTab === "register" && (
          <form className="space-y-3.5" onSubmit={(e) => e.preventDefault()}>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Prénom</label>
                <input type="text" placeholder="Ahmed" className="input-text" />
              </div>
              <div>
                <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Nom</label>
                <input type="text" placeholder="Ben Salah" className="input-text" />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Email</label>
              <input type="email" placeholder="ahmed@exemple.tn" className="input-text" />
            </div>

            <div>
              <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Téléphone (+216)</label>
              <input type="tel" placeholder="20 123 456" className="input-text" />
            </div>

            <div>
              <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Mot de passe</label>
              <input type="password" placeholder="Minimum 8 caractères" className="input-text" />
            </div>

            <div>
              <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Confirmer mot de passe</label>
              <input type="password" placeholder="Retapez votre mot de passe" className="input-text" />
            </div>

            <button
              type="button"
              onClick={() => setActiveTab("otp")}
              className="btn-red w-full mt-2"
            >
              Continuer vers la vérification
            </button>

            <div className="text-center pt-1 text-xs text-[var(--color-steel)]">
              Déjà un compte ?{" "}
              <button
                type="button"
                onClick={() => setActiveTab("login")}
                className="font-semibold text-[var(--color-bts-red)] hover:underline"
              >
                Se connecter
              </button>
            </div>
          </form>
        )}

        {/* ── Form: OTP Challenge ── */}
        {activeTab === "otp" && (
          <div className="space-y-5">
            <div className="p-3 bg-[var(--color-mist)] rounded text-xs text-[var(--color-steel)] text-center">
              Code envoyé à <span className="font-mono font-semibold text-[var(--color-navy)]">{"{{userEmail}}"}</span>
            </div>

            <div className="flex justify-center gap-2">
              {[1, 2, 3, 4, 5, 6].map((idx) => (
                <input
                  key={idx}
                  type="text"
                  maxLength={1}
                  defaultValue={idx <= 3 ? `${idx * 2}` : ""}
                  className="w-11 h-12 text-center text-lg font-mono font-bold border-2 border-[var(--color-rule)] focus:border-[var(--color-bts-red)] rounded-md outline-none transition-colors"
                />
              ))}
            </div>

            <button type="button" className="btn-red w-full">
              Confirmer et accéder à mon espace
            </button>

            <div className="text-center text-xs text-[var(--color-steel)] space-y-1">
              <div>Vous n&apos;avez pas reçu le code ?</div>
              <button type="button" className="font-semibold text-[var(--color-bts-red)] hover:underline">
                Renvoyer le code (59s)
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
