import { useState } from "react";
import { Ico } from "./Icons";

type DashboardSubView = "overview" | "applications" | "appointments" | "documents" | "messages" | "profile";
type DashboardMode = "dynamic_placeholders" | "empty_states" | "loading_skeletons";

export function DashboardView() {
  const [subView, setSubView] = useState<DashboardSubView>("overview");
  const [mode, setMode] = useState<DashboardMode>("dynamic_placeholders");
  const [cancelModalOpen, setCancelModalOpen] = useState(false);
  const [appointmentCancelled, setAppointmentCancelled] = useState(false);
  const [notificationsOpen, setNotificationsOpen] = useState(false);

  return (
    <div className="space-y-6 pb-20 max-w-6xl mx-auto">
      {/* ── Inspector Bar ── */}
      <div className="figma-card p-4 bg-white border-l-4 border-l-[var(--color-navy)] flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-2">
          <span className="text-xs font-bold uppercase text-[var(--color-navy)]">Section Dashboard :</span>
          {(["overview", "applications", "appointments", "documents", "messages", "profile"] as DashboardSubView[]).map((v) => (
            <button
              key={v}
              onClick={() => setSubView(v)}
              className={`px-3 py-1.5 rounded text-xs font-semibold transition-colors capitalize ${
                subView === v
                  ? "bg-[var(--color-bts-red)] text-white"
                  : "bg-[var(--color-mist)] text-[var(--color-steel)] hover:bg-gray-200"
              }`}
            >
              {v === "overview" && "Aperçu Global"}
              {v === "applications" && "Mes Demandes"}
              {v === "appointments" && "Rendez-vous"}
              {v === "documents" && "Documents"}
              {v === "messages" && "Discussion Conseiller"}
              {v === "profile" && "Profil"}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-2">
          <span className="text-xs font-bold uppercase text-[var(--color-navy)]">Simulation Données :</span>
          {(["dynamic_placeholders", "empty_states", "loading_skeletons"] as DashboardMode[]).map((m) => (
            <button
              key={m}
              onClick={() => setMode(m)}
              className={`px-2.5 py-1 rounded text-[11px] font-medium transition-colors ${
                mode === m
                  ? "bg-[var(--color-navy)] text-white"
                  : "bg-[var(--color-mist)] text-[var(--color-steel)] hover:bg-gray-200"
              }`}
            >
              {m === "dynamic_placeholders" && "Variables API {{...}}"}
              {m === "empty_states" && "États Vides (Empty)"}
              {m === "loading_skeletons" && "Squelettes (Loading)"}
            </button>
          ))}
        </div>
      </div>

      {/* ── Dashboard Shell ── */}
      <div className="figma-card bg-white overflow-hidden shadow-sm">
        {/* Top Navbar */}
        <div className="border-b border-[var(--color-rule)] px-6 py-4 flex items-center justify-between">
          <div className="flex items-center gap-4">
            <span className="font-display text-lg font-semibold text-[var(--color-navy)]">
              Espace Client BTS
            </span>
            <span className="badge badge-neutral text-[10px]">Session Sécurisée</span>
          </div>

          <div className="flex items-center gap-4">
            {/* Notification trigger button */}
            <button
              onClick={() => setNotificationsOpen(!notificationsOpen)}
              className="relative p-2 text-[var(--color-steel)] hover:text-[var(--color-navy)] rounded-full hover:bg-[var(--color-mist)]"
              aria-label="Notifications"
            >
              <Ico.Bell size={18} />
              <span className="absolute top-1 right-1 w-2 h-2 rounded-full bg-[var(--color-bts-red)]"></span>
            </button>

            <div className="flex items-center gap-2 pl-3 border-l border-[var(--color-rule)]">
              <div className="size-8 rounded-full bg-[var(--color-bts-red-tint)] text-[var(--color-bts-red)] font-bold text-xs flex items-center justify-center">
                CL
              </div>
              <div className="hidden sm:block text-left">
                <div className="text-xs font-semibold text-[var(--color-navy)]">{"{{clientName}}"}</div>
                <div className="text-[10px] text-[var(--color-steel)]">Compte vérifié</div>
              </div>
            </div>
          </div>
        </div>

        {/* Notifications Drawer */}
        {notificationsOpen && (
          <div className="border-b border-[var(--color-rule)] bg-[var(--color-mist)] p-4 animate-in slide-in-from-top duration-150">
            <div className="flex items-center justify-between mb-3">
              <div className="text-xs font-bold uppercase text-[var(--color-navy)] flex items-center gap-2">
                <Ico.Bell size={14} className="text-[var(--color-bts-red)]" /> Notifications ({"{{unreadNotifications}}"})
              </div>
              <button onClick={() => setNotificationsOpen(false)} className="text-xs text-[var(--color-steel)] hover:text-[var(--color-navy)]">
                Fermer
              </button>
            </div>

            {mode === "empty_states" ? (
              <div className="text-center py-6 text-xs text-[var(--color-steel)]">
                Aucune notification.
              </div>
            ) : (
              <div className="space-y-2">
                {[
                  { title: "Dossier de crédit", desc: "Votre demande {{applicationId}} est passée au statut {{applicationStatus}}.", time: "Aujourd'hui" },
                  { title: "Rendez-vous planifié", desc: "Un créneau en agence vous est proposé pour le {{appointmentDate}}.", time: "Hier" },
                ].map((notif, i) => (
                  <div key={i} className="p-3 bg-white rounded border border-[var(--color-rule)] flex justify-between items-start gap-4">
                    <div>
                      <div className="text-xs font-semibold text-[var(--color-navy)]">{notif.title}</div>
                      <div className="text-xs text-[var(--color-steel)] mt-0.5">{notif.desc}</div>
                    </div>
                    <span className="text-[10px] text-gray-400 shrink-0">{notif.time}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {/* ── Main Content Body ── */}
        <div className="p-6 sm:p-8 space-y-8">
          {/* Welcome & Banking Overview */}
          <div className="flex flex-col md:flex-row md:items-center justify-between gap-6 pb-6 border-b border-[var(--color-rule)]">
            <div>
              <p className="overline mb-1">Espace Personnel</p>
              <h1 className="font-display text-2xl font-light text-[var(--color-navy)]">
                Bonjour, <span className="placeholder-tag font-sans text-lg">{"{{clientName}}"}</span>
              </h1>
              <p className="text-xs text-[var(--color-steel)] mt-1">
                Bienvenue sur votre espace bancaire en ligne BTS Bank.
              </p>
            </div>

            {/* Financial Balance (Zero Fake Data) */}
            <div className="p-4 bg-[var(--color-mist)] border border-[var(--color-rule)] rounded-lg min-w-[240px]">
              <div className="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-steel)]">
                Mon solde bancaire
              </div>
              <div className="mt-1">
                {mode === "dynamic_placeholders" && (
                  <span className="placeholder-tag text-base">{"{{balance}}"}</span>
                )}
                {mode === "empty_states" && (
                  <span className="text-sm font-semibold text-[var(--color-steel)] italic">Solde non disponible</span>
                )}
                {mode === "loading_skeletons" && (
                  <div className="h-6 w-28 bg-gray-200 animate-pulse rounded"></div>
                )}
              </div>
              <div className="text-[10px] text-[var(--color-steel)] mt-1">Données fournies par le système bancaire central</div>
            </div>
          </div>

          {/* Quick Actions Band */}
          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3">
            {[
              { label: "Demander un crédit", action: "applications", icon: <Ico.File /> },
              { label: "Mes demandes", action: "applications", icon: <Ico.Arrow /> },
              { label: "Mes rendez-vous", action: "appointments", icon: <Ico.Cal /> },
              { label: "Mes documents", action: "documents", icon: <Ico.Upload /> },
              { label: "Messages", action: "messages", icon: <Ico.Msg /> },
              { label: "Profil", action: "profile", icon: <Ico.User /> },
            ].map((qa, i) => (
              <button
                key={i}
                onClick={() => setSubView(qa.action as DashboardSubView)}
                className="p-3 bg-[var(--color-mist)] hover:bg-[var(--color-bts-red-tint)] border border-[var(--color-rule)] hover:border-[var(--color-bts-red)] rounded-md text-left transition-colors group"
              >
                <div className="text-[var(--color-bts-red)] mb-2">{qa.icon}</div>
                <div className="text-xs font-semibold text-[var(--color-navy)] group-hover:text-[var(--color-bts-red)]">
                  {qa.label}
                </div>
              </button>
            ))}
          </div>

          {/* ── SubView 1: Applications Overview ── */}
          {(subView === "overview" || subView === "applications") && (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <h3 className="text-base font-semibold text-[var(--color-navy)]">Mes Demandes de Financement</h3>
                <button className="btn-red text-xs" style={{ padding: "6px 14px" }}>
                  + Nouvelle demande
                </button>
              </div>

              {mode === "loading_skeletons" && (
                <div className="space-y-3">
                  {[1, 2].map((k) => (
                    <div key={k} className="p-4 border border-[var(--color-rule)] rounded-md space-y-2 animate-pulse">
                      <div className="h-4 bg-gray-200 rounded w-1/3"></div>
                      <div className="h-3 bg-gray-100 rounded w-1/2"></div>
                    </div>
                  ))}
                </div>
              )}

              {mode === "empty_states" && (
                <div className="p-8 text-center border-2 border-dashed border-[var(--color-rule)] rounded-lg space-y-2">
                  <Ico.File size={28} className="mx-auto text-gray-300" />
                  <div className="text-sm font-semibold text-[var(--color-navy)]">Aucune demande pour le moment.</div>
                  <p className="text-xs text-[var(--color-steel)]">Vous n&apos;avez encore soumis aucun dossier de crédit.</p>
                </div>
              )}

              {mode === "dynamic_placeholders" && (
                <div className="figma-card divide-y divide-[var(--color-rule)]">
                  {[
                    { id: "{{applicationId}}", type: "Crédit Professionnel", branch: "{{agencyName}}", status: "En cours d'étude", badge: "badge-warning" },
                    { id: "{{applicationId_2}}", type: "Fonds de roulement", branch: "{{agencyName}}", status: "SUBMITTED", badge: "badge-info" },
                  ].map((app, i) => (
                    <div key={i} className="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                      <div>
                        <div className="flex items-center gap-2">
                          <span className="font-mono text-xs font-bold text-[var(--color-navy)]">{app.id}</span>
                          <span className="text-xs text-[var(--color-steel)]">· {app.type}</span>
                        </div>
                        <div className="text-xs text-[var(--color-steel)] mt-1 flex items-center gap-1.5">
                          <Ico.Pin size={12} className="text-[var(--color-bts-red)]" /> Agence : <span className="placeholder-tag text-[10px]">{app.branch}</span>
                        </div>
                      </div>
                      <div className="flex items-center gap-3">
                        <span className={`badge ${app.badge}`}>{app.status}</span>
                        <button className="btn-outline text-xs" style={{ padding: "6px 12px" }}>
                          Consulter <Ico.Arrow size={12} />
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* ── SubView 2: Appointments Section ── */}
          {subView === "appointments" && (
            <div className="space-y-4">
              <h3 className="text-base font-semibold text-[var(--color-navy)]">Mes Rendez-vous en Agence</h3>

              {mode === "empty_states" || appointmentCancelled ? (
                <div className="p-8 text-center border-2 border-dashed border-[var(--color-rule)] rounded-lg space-y-3">
                  <Ico.Cal size={28} className="mx-auto text-gray-300" />
                  <div className="text-sm font-semibold text-[var(--color-navy)]">
                    {appointmentCancelled ? "Rendez-vous annulé" : "Aucun rendez-vous disponible."}
                  </div>
                  <p className="text-xs text-[var(--color-steel)]">
                    {appointmentCancelled
                      ? "Votre rendez-vous a bien été annulé. Vous pouvez contacter votre conseiller ci-dessous."
                      : "Dès que votre dossier sera validé par la direction, un créneau vous sera proposé."}
                  </p>
                  {appointmentCancelled && (
                    <button
                      onClick={() => setSubView("messages")}
                      className="btn-red text-xs mt-2"
                    >
                      <Ico.Msg size={14} /> Discussion avec votre conseiller
                    </button>
                  )}
                </div>
              ) : (
                <div className="figma-card p-6 space-y-4 border-l-4 border-l-[var(--color-bts-red)]">
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-[var(--color-rule)] pb-4">
                    <div>
                      <div className="text-xs font-semibold text-[var(--color-bts-red)] uppercase tracking-wider">
                        Rendez-vous automatiquement planifié
                      </div>
                      <h4 className="text-sm font-bold text-[var(--color-navy)] mt-0.5">
                        Signature et examen des originaux en agence
                      </h4>
                    </div>
                    <span className="badge badge-success text-xs">{"{{appointmentStatus}}"}</span>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                    <div>
                      <div className="text-[10px] text-[var(--color-steel)] uppercase">Agence Affectée</div>
                      <div className="font-semibold text-[var(--color-navy)] mt-0.5 placeholder-tag">{"{{agencyName}}"}</div>
                    </div>
                    <div>
                      <div className="text-[10px] text-[var(--color-steel)] uppercase">Date & Heure</div>
                      <div className="font-semibold text-[var(--color-navy)] mt-0.5 placeholder-tag">{"{{appointmentDate}}"} à {"{{appointmentTime}}"}</div>
                    </div>
                    <div>
                      <div className="text-[10px] text-[var(--color-steel)] uppercase">Adresse de l&apos;agence</div>
                      <div className="font-semibold text-[var(--color-navy)] mt-0.5 placeholder-tag">{"{{agencyAddress}}"}</div>
                    </div>
                  </div>

                  <div className="pt-2 flex justify-end gap-3">
                    <button
                      onClick={() => setCancelModalOpen(true)}
                      className="text-xs text-[var(--color-error)] hover:underline font-semibold"
                    >
                      Annuler le rendez-vous
                    </button>
                  </div>
                </div>
              )}

              {/* Cancellation Confirmation Modal */}
              {cancelModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                  <div className="bg-white rounded-lg p-6 max-w-md w-full space-y-4 shadow-2xl border border-[var(--color-rule)]">
                    <h4 className="text-base font-bold text-[var(--color-navy)]">Confirmer l&apos;annulation</h4>
                    <p className="text-xs text-[var(--color-steel)] leading-relaxed">
                      Êtes-vous sûr de vouloir annuler ce rendez-vous ? Votre dossier restera actif et votre conseiller BTS sera notifié.
                    </p>
                    <div className="flex justify-end gap-2 pt-2">
                      <button onClick={() => setCancelModalOpen(false)} className="btn-outline text-xs">Retour</button>
                      <button
                        onClick={() => {
                          setAppointmentCancelled(true);
                          setCancelModalOpen(false);
                        }}
                        className="btn-red text-xs"
                      >
                        Confirmer l&apos;annulation
                      </button>
                    </div>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* ── SubView 3: Client ↔ Staff Discussion ── */}
          {subView === "messages" && (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="text-base font-semibold text-[var(--color-navy)]">Discussion avec votre conseiller</h3>
                  <p className="text-xs text-[var(--color-steel)]">Canal de messagerie sécurisé lié au dossier {"{{applicationId}}"}</p>
                </div>
                <span className="badge badge-success text-[10px]">● En ligne (Reverb)</span>
              </div>

              {mode === "empty_states" ? (
                <div className="p-8 text-center border-2 border-dashed border-[var(--color-rule)] rounded-lg">
                  <Ico.Msg size={28} className="mx-auto text-gray-300 mb-2" />
                  <div className="text-xs font-semibold text-[var(--color-navy)]">Aucun message pour le moment.</div>
                  <p className="text-xs text-[var(--color-steel)] mt-1">Vous pouvez poser une question directement à votre agence ci-dessous.</p>
                </div>
              ) : (
                <div className="figma-card p-4 space-y-4 bg-[var(--color-mist)]">
                  {/* Message Stream */}
                  <div className="space-y-3 max-h-80 overflow-y-auto pr-2">
                    {/* Staff Message */}
                    <div className="flex items-start gap-2.5 max-w-[80%]">
                      <div className="size-7 rounded-full bg-[var(--color-navy)] text-white text-[10px] font-bold flex items-center justify-center shrink-0">
                        BTS
                      </div>
                      <div className="p-3 bg-white rounded-lg rounded-tl-none border border-[var(--color-rule)] shadow-xs">
                        <div className="text-[10px] font-bold text-[var(--color-navy)] mb-1">Conseiller BTS Bank ({"{{agencyName}}"})</div>
                        <div className="text-xs text-[var(--color-ink)] leading-relaxed">
                          Bonjour {"{{clientName}}"}, nous avons bien reçu votre devis d&apos;équipement. Veuillez vous assurer que le cachet du fournisseur est lisible.
                        </div>
                        <div className="text-[9px] text-gray-400 mt-1 text-right">10:42 · Envoyé</div>
                      </div>
                    </div>

                    {/* Client Message */}
                    <div className="flex items-start gap-2.5 max-w-[80%] ml-auto flex-row-reverse">
                      <div className="size-7 rounded-full bg-[var(--color-bts-red)] text-white text-[10px] font-bold flex items-center justify-center shrink-0">
                        MOI
                      </div>
                      <div className="p-3 bg-[var(--color-bts-red)] text-white rounded-lg rounded-tr-none shadow-xs">
                        <div className="text-xs leading-relaxed">
                          Bonjour, j&apos;ai téléversé la version scannée en haute résolution dans l&apos;onglet Documents.
                        </div>
                        <div className="text-[9px] text-white/70 mt-1 text-right">10:45 · Envoyé</div>
                      </div>
                    </div>
                  </div>

                  {/* Input area */}
                  <div className="flex gap-2 pt-2 border-t border-[var(--color-rule)]">
                    <input
                      type="text"
                      placeholder="Écrire un message à votre conseiller..."
                      className="input-text flex-1"
                    />
                    <button className="btn-red text-xs px-4">
                      Envoyer
                    </button>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* ── SubView 4: Documents Section ── */}
          {subView === "documents" && (
            <div className="space-y-4">
              <h3 className="text-base font-semibold text-[var(--color-navy)]">Pièces Justificatives Déposées</h3>
              <div className="figma-card divide-y divide-[var(--color-rule)]">
                {[
                  { name: "Carte d'Identité Nationale (CIN)", status: "Document disponible", badge: "badge-success", filename: "CIN_{{clientName}}.pdf" },
                  { name: "Devis / Facture Proforma Équipement", status: "Document en attente de révision", badge: "badge-warning", filename: "DEVIS_FDR_{{projectCode}}.pdf" },
                  { name: "Plan d'affaires simplifié", status: "Document requis", badge: "badge-red", filename: "{{documentName}}" },
                ].map((doc, i) => (
                  <div key={i} className="p-4 flex items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                      <Ico.File size={20} className="text-[var(--color-bts-red)] shrink-0" />
                      <div>
                        <div className="text-xs font-semibold text-[var(--color-navy)]">{doc.name}</div>
                        <div className="text-[11px] font-mono text-[var(--color-steel)]">{doc.filename}</div>
                      </div>
                    </div>
                    <div className="flex items-center gap-3">
                      <span className={`badge ${doc.badge}`}>{doc.status}</span>
                      <button className="btn-outline text-xs" style={{ padding: "6px 12px" }}>
                        <Ico.Upload size={13} /> Mettre à jour
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* ── SubView 5: Profile Section ── */}
          {subView === "profile" && (
            <div className="space-y-4 max-w-xl">
              <h3 className="text-base font-semibold text-[var(--color-navy)]">Informations Personnelles du Client</h3>
              <div className="figma-card p-6 space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-[10px] font-semibold text-[var(--color-steel)] uppercase">Nom</label>
                    <div className="text-xs font-semibold text-[var(--color-navy)] mt-1 placeholder-tag">{"{{lastName}}"}</div>
                  </div>
                  <div>
                    <label className="block text-[10px] font-semibold text-[var(--color-steel)] uppercase">Prénom</label>
                    <div className="text-xs font-semibold text-[var(--color-navy)] mt-1 placeholder-tag">{"{{firstName}}"}</div>
                  </div>
                </div>
                <div>
                  <label className="block text-[10px] font-semibold text-[var(--color-steel)] uppercase">Adresse Email</label>
                  <div className="text-xs font-semibold text-[var(--color-navy)] mt-1 placeholder-tag">{"{{email}}"}</div>
                </div>
                <div>
                  <label className="block text-[10px] font-semibold text-[var(--color-steel)] uppercase">Numéro de Téléphone</label>
                  <div className="text-xs font-semibold text-[var(--color-navy)] mt-1 placeholder-tag">{"{{phone}}"}</div>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
