'use client';

import { useEffect, useState, type FormEvent } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { ApplicationStepper } from '@/components/application-stepper';
import { Breadcrumbs, BackLink } from '@/components/breadcrumbs';
import {
  getApplication,
  updateProject,
  type ProjectDto,
  type CreditApplicationDto,
} from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { PageLoading } from '@/components/page-loading';
import { ArrowRight, Lock, MapPin } from 'lucide-react';

type ProjectFormState = Omit<ProjectDto, 'code_projet' | 'identifiant_personne'>;

const EMPTY_FORM: ProjectFormState = {
  nom_ou_rs: '',
  prenom_ou_dc: '',
  type_projet: '',
  objet: '',
  adresse: '',
  ville: '',
  code_postal: '',
  activite: '',
  description: '',
  delegation: '',
  localisation: '',
  latitude: null,
  longitude: null,
  cout: '',
  investissement_personnel: '',
  financement: '',
  revenus: '',
  depenses: '',
};

export default function ProjectStepPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [application, setApplication] = useState<CreditApplicationDto | null>(null);
  const [form, setForm] = useState<ProjectFormState>(EMPTY_FORM);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]> | null>(null);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }
    getApplication(applicationId)
      .then(({ application }) => {
        setApplication(application);
        const defaultNom = application.credit_request?.nom_ou_rs || (application.client ? `${application.client.nom} ${application.client.prenom}`.trim() : '');
        const defaultPrenom = application.credit_request?.prenom_ou_dc || application.client?.prenom || '';
        const defaultFinancement = application.credit_request?.montant_global_sollicite || '';

        if (application.project) {
          const { code_projet, identifiant_personne, ...rest } = application.project;
          void code_projet;
          void identifiant_personne;
          setForm({
            ...EMPTY_FORM,
            ...rest,
            nom_ou_rs: rest.nom_ou_rs || defaultNom,
            prenom_ou_dc: rest.prenom_ou_dc || defaultPrenom,
            financement: rest.financement || defaultFinancement,
            localisation: rest.localisation || rest.delegation || rest.ville || '',
          });
        } else {
          setForm((prev) => ({
            ...prev,
            nom_ou_rs: defaultNom,
            prenom_ou_dc: defaultPrenom,
            financement: defaultFinancement,
          }));
        }
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Impossible de charger la demande.'))
      .finally(() => setLoading(false));
  }, [applicationId, router]);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setFieldErrors(null);
    setSubmitting(true);
    try {
      const defaultNom = application?.credit_request?.nom_ou_rs || (application?.client ? `${application.client.nom} ${application.client.prenom}`.trim() : 'Demandeur');
      const defaultPrenom = application?.credit_request?.prenom_ou_dc || application?.client?.prenom || 'BTS';
      const calculatedFinancement = form.financement !== '' ? String(form.financement) : String(Math.max(0, Number(form.cout || 0) - Number(form.investissement_personnel || 0)));

      const payload = {
        ...form,
        nom_ou_rs: form.nom_ou_rs || defaultNom,
        prenom_ou_dc: form.prenom_ou_dc || defaultPrenom,
        localisation: form.localisation || form.delegation || form.ville || 'Tunisie',
        financement: calculatedFinancement,
        cout: String(form.cout || 0),
        investissement_personnel: String(form.investissement_personnel || 0),
        revenus: String(form.revenus || 0),
        depenses: String(form.depenses || 0),
      };

      const { application: updatedApp } = await updateProject(applicationId, payload);
      setApplication(updatedApp);
      router.push(`/applications/${applicationId}/validation`);
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
        setFieldErrors(err.fields ?? null);
      } else {
        setError('Une erreur est survenue lors de l\u2019enregistrement.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (loading) return <PageLoading />;

  if (!application) {
    return (
      <div className="portal-shell">
        <DashboardHeader />
        <main id="main" className="mx-auto max-w-3xl px-4 py-10 space-y-4">
          <BackLink href="/applications" label="Retour à mes demandes" />
          <ErrorAlert message={error} />
        </main>
      </div>
    );
  }

  const locked = application.is_locked;

  return (
    <div className="portal-shell">
      <DashboardHeader />
      <main id="main" className="mx-auto max-w-4xl px-4 sm:px-8 py-8 sm:py-10 space-y-6">
        <BackLink href={`/applications/${applicationId}`} label="Retour aux détails" />
        <Breadcrumbs
          items={[
            { label: 'Tableau de bord', href: '/dashboard' },
            { label: 'Mes demandes', href: '/applications' },
            { label: application.credit_request?.n_demande ?? `Dossier #${applicationId}`, href: `/applications/${applicationId}` },
            { label: 'Descriptif du Projet' },
          ]}
          className="mb-2"
        />

        {/* ── Persistent System Reference Banner ── */}
        <div className="figma-card p-4 bg-white border-l-4 border-l-[#C0272D] flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-2">
            <Lock className="size-4 text-[#C0272D]" />
            <span className="text-xs font-bold uppercase tracking-wider text-[#0C1825]">
              Référence Système Protégée :
            </span>
            <span className="font-mono text-xs font-bold text-[#C0272D] bg-[#FDF2F2] px-2 py-0.5 rounded border border-[#FECACA]">
              {application.credit_request?.n_demande ?? `DOSSIER-#${applicationId}`}
            </span>
          </div>
        </div>

        {/* ── Stepper ── */}
        <ApplicationStepper status={application.status} current="project" />

        {/* ── Project form ── */}
        <form onSubmit={handleSubmit} className="space-y-6">
          {/* ── 1. Form Card : Descriptif du Projet & Localisation ── */}
          <div className="figma-card p-6 sm:p-8 bg-white space-y-6 shadow-xs border border-[#E0E4E9]">
            <div className="border-b border-[#E0E4E9] pb-4">
              <p className="overline">Étape 3 sur 5</p>
              <h1 className="font-display text-2xl font-light text-[#0C1825]">
                Descriptif du Projet & Localisation
              </h1>
              <p className="text-xs text-[#3D5166] mt-1">
                Détaillez la nature de votre activité, l&apos;implantation géographique et le modèle économique prévisionnel.
              </p>
            </div>

            <ErrorAlert message={error} fields={fieldErrors} />

            <fieldset disabled={locked} className="space-y-5 disabled:opacity-60">
              {/* Promoteur / Raison Sociale */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormInput
                  label="Nom ou Raison Sociale du promoteur"
                  id="nom_ou_rs"
                  value={form.nom_ou_rs}
                  onChange={(v) => setForm({ ...form, nom_ou_rs: v })}
                  placeholder="Ex: Société Exemple"
                  required
                />
                <FormInput
                  label="Prénom ou Dénomination Commerciale"
                  id="prenom_ou_dc"
                  value={form.prenom_ou_dc}
                  onChange={(v) => setForm({ ...form, prenom_ou_dc: v })}
                  placeholder="Ex: Enseigne commerciale"
                  required
                />
              </div>

              {/* Type de projet, Secteur & Objet */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="space-y-1.5">
                  <label htmlFor="type_projet" className="text-xs font-semibold text-[#0C1825]">
                    Type de projet <span className="text-[#C0272D]">*</span>
                  </label>
                  <select
                    id="type_projet"
                    value={form.type_projet}
                    onChange={(e) => setForm({ ...form, type_projet: e.target.value })}
                    className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3 py-2.5 text-[#0C1825] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]"
                    required
                  >
                    <option value="">Sélectionner</option>
                    <option value="Création">Création d&apos;entreprise</option>
                    <option value="Extension">Extension d&apos;activité</option>
                    <option value="Modernisation">Modernisation / Rénovation</option>
                  </select>
                </div>
                <FormInput
                  label="Secteur d'activité"
                  id="activite"
                  value={form.activite}
                  onChange={(v) => setForm({ ...form, activite: v })}
                  placeholder="Ex: Agriculture, Services, Industrie..."
                  required
                />
                <FormInput
                  label="Objet du projet"
                  id="objet"
                  value={form.objet}
                  onChange={(v) => setForm({ ...form, objet: v })}
                  placeholder="Ex: Achat d'équipements de menuiserie"
                  required
                />
              </div>

              {/* Description */}
              <div className="space-y-1.5">
                <label htmlFor="description" className="text-xs font-semibold text-[#0C1825] flex items-center justify-between">
                  <span>Description synthétique du projet <span className="text-[#C0272D]">*</span></span>
                  <span className="text-[10px] text-[#3D5166]">Présentez vos objectifs et vos clients cibles</span>
                </label>
                <textarea
                  id="description"
                  rows={3}
                  required
                  value={form.description}
                  onChange={(e) => setForm({ ...form, description: e.target.value })}
                  placeholder="Expliquez brièvement les activités, la clientèle visée, les équipements prévus..."
                  className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg p-3 text-[#0C1825] placeholder:text-gray-400 focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D] transition-colors"
                />
              </div>

              {/* Localisation & Coordonnées GPS */}
              <div className="p-4 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] space-y-4">
                <div className="flex items-center justify-between">
                  <div className="text-xs font-bold uppercase tracking-wider text-[#0C1825] flex items-center gap-1.5">
                    <MapPin className="size-3.5 text-[#C0272D]" />
                    <span>Implantation Géographique &amp; Coordonnées GPS</span>
                  </div>
                  <span className="text-[10px] text-[#3D5166]">Localisation exacte de l&apos;activité</span>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <FormInput
                    label="Adresse du local / siège"
                    id="adresse"
                    value={form.adresse}
                    onChange={(v) => setForm({ ...form, adresse: v })}
                    placeholder="Ex: 15 Rue de la République"
                    required
                  />
                  <FormInput
                    label="Ville / Commune"
                    id="ville"
                    value={form.ville}
                    onChange={(v) => setForm({ ...form, ville: v })}
                    placeholder="Ex: Ariana"
                    required
                  />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <FormInput
                    label="Délégation"
                    id="delegation"
                    value={form.delegation}
                    onChange={(v) => setForm({ ...form, delegation: v })}
                    placeholder="Ex: Ariana Ville"
                    required
                  />
                  <FormInput
                    label="Code postal"
                    id="code_postal"
                    value={form.code_postal}
                    onChange={(v) => setForm({ ...form, code_postal: v })}
                    placeholder="Ex: 2080"
                    required
                  />
                </div>

              </div>

              {/* Paramètres Financiers du Projet */}
              <div className="p-4 bg-[#FAFBFD] rounded-xl border border-[#E0E4E9] space-y-4">
                <div className="text-xs font-bold uppercase tracking-wider text-[#0C1825]">
                  Plan de Financement &amp; Rentabilité Prévisionnelle (TND)
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <FormInput
                    label="Coût total du projet"
                    id="cout"
                    value={form.cout}
                    onChange={(v) => setForm({ ...form, cout: v })}
                    placeholder="Ex: 60000"
                    required
                  />
                  <FormInput
                    label="Investissement personnel"
                    id="investissement_personnel"
                    value={form.investissement_personnel}
                    onChange={(v) => setForm({ ...form, investissement_personnel: v })}
                    placeholder="Ex: 10000"
                    required
                  />
                  <FormInput
                    label="Crédit sollicité (BTS)"
                    id="financement"
                    value={form.financement}
                    onChange={(v) => setForm({ ...form, financement: v })}
                    placeholder="Ex: 50000"
                    required
                  />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <FormInput
                    label="Revenus mensuels prévisionnels"
                    id="revenus"
                    value={form.revenus}
                    onChange={(v) => setForm({ ...form, revenus: v })}
                    placeholder="Ex: 4500"
                    required
                  />
                  <FormInput
                    label="Dépenses mensuelles prévisionnelles"
                    id="depenses"
                    value={form.depenses}
                    onChange={(v) => setForm({ ...form, depenses: v })}
                    placeholder="Ex: 2200"
                    required
                  />
                </div>
              </div>
            </fieldset>
          </div>

          {/* ── Bottom action bar ── */}
          {!locked && (
            <div className="figma-card p-4 sm:p-5 bg-white border border-[#E0E4E9] flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-xs rounded-xl">
              <div className="text-xs text-[#3D5166]">
                Vérifiez le descriptif de votre projet avant de passer au récapitulatif final.
              </div>
              <button
                type="submit"
                disabled={submitting}
                className="btn-red shrink-0 justify-center gap-2 px-7 text-xs shadow-xs"
              >
                <span>{submitting ? 'Enregistrement…' : 'Étape suivante : Validation'}</span>
                <ArrowRight className="size-4" />
              </button>
            </div>
          )}
        </form>
      </main>
    </div>
  );
}

function FormInput({
  label,
  id,
  value,
  onChange,
  type = 'text',
  placeholder,
  required = false,
  optional = false,
}: {
  label: string;
  id: string;
  value: string;
  onChange: (value: string) => void;
  type?: string;
  placeholder?: string;
  required?: boolean;
  optional?: boolean;
}) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-xs font-semibold text-[#0C1825] flex items-center justify-between">
        <span>
          {label} {required && <span className="text-[#C0272D]">*</span>}
        </span>
        {optional && <span className="text-[10px] text-[#3D5166] font-normal">(Optionnel)</span>}
      </label>
      <input
        id={id}
        type={type}
        required={required}
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3 py-2.5 text-[#0C1825] placeholder:text-gray-400 focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D] transition-colors"
      />
    </div>
  );
}
