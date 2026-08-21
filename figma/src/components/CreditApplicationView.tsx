import { useState } from "react";
import { Ico } from "./Icons";

type Stage = "stage1_client" | "stage2_credit" | "stage3_project" | "stage4_documents" | "validation" | "submitted";

interface CategoryState {
  code: string;
  label: string;
  completed: boolean;
  docAttached: boolean;
}

export function CreditApplicationView() {
  const [currentStage, setCurrentStage] = useState<Stage>("stage1_client");
  const [validationStep, setValidationStep] = useState<1 | 2>(1);

  // Financing categories state
  const [categories, setCategories] = useState<CategoryState[]>([
    { code: "FDR", label: "Fonds de Roulement", completed: true, docAttached: true },
    { code: "AMG", label: "Aménagement des Locaux", completed: false, docAttached: false },
    { code: "CHP", label: "Achat de Cheptel", completed: false, docAttached: false },
    { code: "EPR", label: "Équipement Professionnel", completed: true, docAttached: false },
  ]);

  const atLeastOneCompleted = categories.some((c) => c.completed);
  const completedWithoutDoc = categories.filter((c) => c.completed && !c.docAttached);

  const toggleCategory = (code: string) => {
    setCategories(
      categories.map((c) =>
        c.code === code ? { ...c, completed: !c.completed } : c
      )
    );
  };

  const toggleDoc = (code: string) => {
    setCategories(
      categories.map((c) =>
        c.code === code ? { ...c, docAttached: !c.docAttached } : c
      )
    );
  };

  return (
    <div className="space-y-6 pb-20 max-w-4xl mx-auto">
      {/* ── Stage Navigator Bar (Figma Inspector Tool) ── */}
      <div className="figma-card p-4 bg-white border-l-4 border-l-[var(--color-bts-red)] flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-2">
          <span className="text-xs font-bold uppercase text-[var(--color-navy)]">Étape du Wizard :</span>
          {(
            [
              ["stage1_client", "1. Client"],
              ["stage2_credit", "2. Crédit"],
              ["stage3_project", "3. Projet"],
              ["stage4_documents", "4. Documents (FDR/AMG)"],
              ["validation", "5. Validation 1 & 2"],
              ["submitted", "6. Soumis (SUBMITTED)"],
            ] as [Stage, string][]
          ).map(([st, label]) => (
            <button
              key={st}
              onClick={() => setCurrentStage(st)}
              className={`px-3 py-1.5 rounded text-xs font-semibold transition-colors ${
                currentStage === st
                  ? "bg-[var(--color-bts-red)] text-white"
                  : "bg-[var(--color-mist)] text-[var(--color-steel)] hover:bg-gray-200"
              }`}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {/* ── Persistent Read-Only Identity Header (Rule #11) ── */}
      <div className="figma-card p-4 bg-[var(--color-mist)] border border-[var(--color-rule)]">
        <div className="text-[10px] font-bold uppercase tracking-wider text-[var(--color-steel)] mb-2 flex items-center gap-1.5">
          <Ico.Lock size={12} className="text-[var(--color-bts-red)]" /> Identité & Référence Dossier (Champs Système Protégés)
        </div>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
          <div>
            <span className="text-[10px] text-[var(--color-steel)] block">Nom du Client</span>
            <span className="placeholder-tag mt-0.5">{"{{lastName}}"}</span>
          </div>
          <div>
            <span className="text-[10px] text-[var(--color-steel)] block">Prénom du Client</span>
            <span className="placeholder-tag mt-0.5">{"{{firstName}}"}</span>
          </div>
          <div>
            <span className="text-[10px] text-[var(--color-steel)] block">Code Projet 🔒</span>
            <span className="placeholder-tag mt-0.5">{"{{projectCode}}"}</span>
          </div>
          <div>
            <span className="text-[10px] text-[var(--color-steel)] block">Identifiant Personne 🔒</span>
            <span className="placeholder-tag mt-0.5">{"{{personId}}"}</span>
          </div>
        </div>
      </div>

      {/* ── Main Form Body ── */}
      <div className="figma-card p-6 sm:p-8 bg-white shadow-sm space-y-6">
        {/* ── Stage 1: Client Information ── */}
        {currentStage === "stage1_client" && (
          <div className="space-y-6">
            <div>
              <p className="overline mb-1">Étape 1 sur 4</p>
              <h2 className="font-display text-xl font-normal text-[var(--color-navy)]">
                Informations Personnelles & Civilité
              </h2>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Type de Pièce d&apos;Identité</label>
                <select className="input-text">
                  <option>Carte d&apos;Identité Nationale (CIN)</option>
                  <option>Passeport</option>
                  <option>Carte de Séjour</option>
                </select>
              </div>
              <div>
                <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Numéro de Pièce</label>
                <input type="text" placeholder="08123456" className="input-text" />
              </div>
              <div>
                <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Date de délivrance</label>
                <input type="date" className="input-text" />
              </div>
              <div>
                <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Lieu de délivrance</label>
                <input type="text" placeholder="Tunis" className="input-text" />
              </div>
            </div>

            <div className="pt-4 flex justify-end">
              <button onClick={() => setCurrentStage("stage2_credit")} className="btn-red">
                Suivant : Demande de Crédit <Ico.Arrow size={14} />
              </button>
            </div>
          </div>
        )}

        {/* ── Stage 2: Credit Request ── */}
        {currentStage === "stage2_credit" && (
          <div className="space-y-6">
            <div>
              <p className="overline mb-1">Étape 2 sur 4</p>
              <h2 className="font-display text-xl font-normal text-[var(--color-navy)]">
                Objet & Montant du Financement
              </h2>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Type de Crédit Sollicité</label>
                <select className="input-text">
                  <option>Crédit Professionnel BTS</option>
                  <option>Crédit d&apos;Investissement</option>
                  <option>Fonds de Roulement</option>
                  <option>Finance Islamique (Mourabaha)</option>
                </select>
              </div>
              <div>
                <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Montant global sollicité (TND)</label>
                <input type="number" placeholder="Montant en Dinars Tunisiens" className="input-text" />
              </div>
              <div>
                <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Gouvernorat de dépôt</label>
                <select className="input-text">
                  <option>Tunis</option>
                  <option>Ariana</option>
                  <option>Sousse</option>
                  <option>Sfax</option>
                  <option>Bizerte</option>
                </select>
              </div>
              <div>
                <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Agence BTS de rattachement</label>
                <input type="text" value="Agence Tunis Centre" readOnly className="input-text readonly" />
              </div>
            </div>

            <div className="pt-4 flex justify-between">
              <button onClick={() => setCurrentStage("stage1_client")} className="btn-outline">
                Retour
              </button>
              <button onClick={() => setCurrentStage("stage3_project")} className="btn-red">
                Suivant : Détails du Projet <Ico.Arrow size={14} />
              </button>
            </div>
          </div>
        )}

        {/* ── Stage 3: Project Details ── */}
        {currentStage === "stage3_project" && (
          <div className="space-y-6">
            <div>
              <p className="overline mb-1">Étape 3 sur 4</p>
              <h2 className="font-display text-xl font-normal text-[var(--color-navy)]">
                Description & Coûts du Projet
              </h2>
            </div>

            <div className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Activité principale du projet</label>
                <input type="text" placeholder="Ex: Atelier de menuiserie aluminium" className="input-text" />
              </div>

              <div>
                <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Description sommaire des besoins</label>
                <textarea rows={3} placeholder="Présentez les objectifs de votre investissement..." className="input-text resize-none" />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Coût Total Estimé (TND)</label>
                  <input type="number" placeholder="Coût global" className="input-text" />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-[var(--color-navy)] mb-1">Apport Personnel (TND)</label>
                  <input type="number" placeholder="Votre autofinancement" className="input-text" />
                </div>
              </div>
            </div>

            <div className="pt-4 flex justify-between">
              <button onClick={() => setCurrentStage("stage2_credit")} className="btn-outline">
                Retour
              </button>
              <button onClick={() => setCurrentStage("stage4_documents")} className="btn-red">
                Suivant : Pièces & Catégories <Ico.Arrow size={14} />
              </button>
            </div>
          </div>
        )}

        {/* ── Stage 4: Financing Categories (FDR, AMG, CHP, EPR) & Documents ── */}
        {currentStage === "stage4_documents" && (
          <div className="space-y-6">
            <div>
              <p className="overline mb-1">Étape 4 sur 4</p>
              <h2 className="font-display text-xl font-normal text-[var(--color-navy)]">
                Catégories de Financement & Documents Justificatifs
              </h2>
              <p className="text-xs text-[var(--color-steel)] mt-1">
                Règle métier obligatoire : <strong>Au moins une catégorie</strong> doit être complétée, et chaque catégorie sélectionnée nécessite son document justificatif joint.
              </p>
            </div>

            {/* Validation Alerts */}
            {!atLeastOneCompleted && (
              <div className="p-3 bg-[var(--color-warning-bg)] border border-[#FDE68A] rounded-md text-xs text-[var(--color-warning)] flex items-center gap-2">
                <Ico.AlertCircle size={15} />
                <strong>Règle manquante :</strong> Veuillez compléter au moins une catégorie de financement.
              </div>
            )}

            {completedWithoutDoc.length > 0 && (
              <div className="p-3 bg-[var(--color-error-bg)] border border-[#FECACA] rounded-md text-xs text-[var(--color-error)] flex items-center gap-2">
                <Ico.AlertCircle size={15} />
                <strong>Document manquant :</strong> Veuillez joindre le document requis pour ({completedWithoutDoc.map((c) => c.code).join(", ")}).
              </div>
            )}

            {/* Categories Table */}
            <div className="figma-card divide-y divide-[var(--color-rule)]">
              {categories.map((cat) => (
                <div key={cat.code} className="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                  <div className="space-y-1">
                    <div className="flex items-center gap-2">
                      <span className="font-mono text-xs font-bold text-[var(--color-bts-red)] px-1.5 py-0.5 bg-[var(--color-bts-red-tint)] rounded">
                        {cat.code}
                      </span>
                      <span className="text-sm font-semibold text-[var(--color-navy)]">{cat.label}</span>
                    </div>
                    <div className="text-xs text-[var(--color-steel)]">
                      {cat.completed ? "Catégorie sélectionnée" : "Non sélectionnée"}
                    </div>
                  </div>

                  <div className="flex items-center gap-3">
                    {/* Toggle Completion */}
                    <button
                      type="button"
                      onClick={() => toggleCategory(cat.code)}
                      className={`px-3 py-1.5 rounded text-xs font-semibold border ${
                        cat.completed
                          ? "bg-[var(--color-navy)] text-white border-[var(--color-navy)]"
                          : "bg-white text-[var(--color-steel)] border-[var(--color-rule)]"
                      }`}
                    >
                      {cat.completed ? "✓ Sélectionné" : "+ Sélectionner"}
                    </button>

                    {/* Toggle Document Upload */}
                    {cat.completed && (
                      <button
                        type="button"
                        onClick={() => toggleDoc(cat.code)}
                        className={`px-3 py-1.5 rounded text-xs font-semibold flex items-center gap-1.5 border ${
                          cat.docAttached
                            ? "bg-emerald-50 text-emerald-700 border-emerald-300"
                            : "bg-amber-50 text-amber-800 border-amber-300"
                        }`}
                      >
                        <Ico.Upload size={12} />
                        {cat.docAttached ? "Document joint ✓" : "Joindre document *"}
                      </button>
                    )}
                  </div>
                </div>
              ))}
            </div>

            <div className="pt-4 flex justify-between">
              <button onClick={() => setCurrentStage("stage3_project")} className="btn-outline">
                Retour
              </button>
              <button
                onClick={() => setCurrentStage("validation")}
                disabled={!atLeastOneCompleted || completedWithoutDoc.length > 0}
                className="btn-red"
              >
                Lancer la Validation <Ico.Arrow size={14} />
              </button>
            </div>
          </div>
        )}

        {/* ── Stage 5: Validation 1 & 2 Flow ── */}
        {currentStage === "validation" && (
          <div className="space-y-6">
            <div>
              <p className="overline mb-1">Contrôle Automatique & Verrouillage</p>
              <h2 className="font-display text-xl font-normal text-[var(--color-navy)]">
                Vérification du Dossier de Crédit
              </h2>
            </div>

            <div className="figma-card p-6 space-y-4">
              <div className="flex items-center gap-3">
                <div className={`size-8 rounded-full flex items-center justify-center font-bold text-xs ${
                  validationStep >= 1 ? "bg-emerald-600 text-white" : "bg-gray-200 text-gray-600"
                }`}>
                  1
                </div>
                <div className="flex-1">
                  <div className="text-xs font-bold text-[var(--color-navy)]">Validation 1 : Audit de complétude & pièces</div>
                  <div className="text-xs text-[var(--color-steel)]">Vérification de la validité des fichiers et conformité des règles métier.</div>
                </div>
                <span className="badge badge-success text-[10px]">Vérifié ✓</span>
              </div>

              <div className="border-t border-[var(--color-rule)] pt-4 flex items-center gap-3">
                <div className={`size-8 rounded-full flex items-center justify-center font-bold text-xs ${
                  validationStep === 2 ? "bg-[var(--color-bts-red)] text-white" : "bg-gray-200 text-gray-600"
                }`}>
                  2
                </div>
                <div className="flex-1">
                  <div className="text-xs font-bold text-[var(--color-navy)]">Validation 2 : Verrouillage FINAL_LOCKED & Soumission</div>
                  <div className="text-xs text-[var(--color-steel)]">Le dossier est scellé et transmis directement à l&apos;agence BTS Bank.</div>
                </div>
                {validationStep === 1 ? (
                  <button
                    onClick={() => {
                      setValidationStep(2);
                      setTimeout(() => setCurrentStage("submitted"), 700);
                    }}
                    className="btn-red text-xs"
                    style={{ padding: "6px 14px" }}
                  >
                    Confirmer Validation 2
                  </button>
                ) : (
                  <span className="badge badge-info text-[10px]">Verrouillage en cours…</span>
                )}
              </div>
            </div>
          </div>
        )}

        {/* ── Stage 6: Automatic Submitted State ── */}
        {currentStage === "submitted" && (
          <div className="text-center py-10 space-y-4">
            <div className="size-16 rounded-full bg-emerald-100 text-emerald-600 mx-auto flex items-center justify-center">
              <Ico.Check size={32} />
            </div>
            <h2 className="font-display text-2xl font-normal text-[var(--color-navy)]">
              Demande soumise avec succès !
            </h2>
            <p className="text-xs text-[var(--color-steel)] max-w-md mx-auto leading-relaxed">
              Votre dossier de crédit est passé au statut <span className="badge badge-info text-xs">SUBMITTED</span>. Il est désormais en cours d&apos;étude par votre agence BTS Bank (<span className="placeholder-tag text-[10px]">{"{{agencyName}}"}</span>).
            </p>
            <div className="p-4 bg-[var(--color-mist)] border border-[var(--color-rule)] rounded-md max-w-sm mx-auto text-xs text-[var(--color-steel)]">
              La transmission a été effectuée automatiquement après la Validation 2. Aucun bouton de soumission supplémentaire n&apos;est nécessaire.
            </div>
            <div className="pt-4">
              <button onClick={() => setCurrentStage("stage1_client")} className="btn-outline text-xs">
                Retourner à la gestion des dossiers
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
