'use client';

import { useEffect, useState, type FormEvent } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { ApplicationStepper } from '@/components/application-stepper';
import { Breadcrumbs, BackLink } from '@/components/breadcrumbs';
import { DocumentsUploadCard } from '@/components/documents-upload-card';
import {
  getApplication,
  updateClient,
  type ClientDto,
  type CreditApplicationDto,
} from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { PageLoading } from '@/components/page-loading';
import { User, ArrowRight, Lock } from 'lucide-react';

type FormState = Omit<ClientDto, 'code_client'>;

const EMPTY_CLIENT: FormState = {
  civilite: '',
  nom: '',
  prenom: '',
  nom_epoux: '',
  deuxieme_prenom: '',
  date_naissance: '',
  lieu_naissance: '',
  pays_naissance: '',
  nationalite: '',
  pays_residence: '',
  etat_civil: '',
  nombre_enfants: 0,
  type_pid: '',
  numero_pid: '',
  date_delivrance_pid: '',
  lieu_delivrance_pid: '',
  numero_carte_sejour: '',
  profession: '',
  date_entree_relation: '',
};

export default function ClientStepPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [application, setApplication] = useState<CreditApplicationDto | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY_CLIENT);
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
        if (application.client) setForm({ ...EMPTY_CLIENT, ...application.client });
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
      const { application } = await updateClient(applicationId, form);
      setApplication(application);
      router.push(`/applications/${applicationId}/credit`);
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
      <div className="min-h-screen bg-[#F4F6F8] text-[#1E2D3D]">
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
    <div className="min-h-screen bg-[#F4F6F8] text-[#1E2D3D]">
      <DashboardHeader />
      <main id="main" className="mx-auto max-w-4xl px-4 sm:px-8 py-8 sm:py-10 space-y-6">
        <BackLink href={`/applications/${applicationId}`} label="Retour aux détails" />
        <Breadcrumbs
          items={[
            { label: 'Tableau de bord', href: '/dashboard' },
            { label: 'Mes demandes', href: '/applications' },
            { label: application.credit_request?.n_demande ?? `Dossier #${applicationId}`, href: `/applications/${applicationId}` },
            { label: 'Informations Demandeur' },
          ]}
          className="mb-2"
        />

        {/* ── Persistent System Reference Banner (Rule #11) ── */}
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
          {application.client?.code_client && (
            <div className="text-xs text-[#3D5166]">
              Code Client : <span className="font-mono font-semibold text-[#0C1825]">{application.client.code_client}</span>
            </div>
          )}
        </div>

        {/* ── Stepper ── */}
        <ApplicationStepper status={application.status} current="client" />

        {/* ── Form Card ── */}
        <div className="figma-card p-6 sm:p-8 bg-white space-y-6">
          <div className="border-b border-[#E0E4E9] pb-4">
            <p className="overline">Étape 1 sur 5</p>
            <h1 className="font-display text-2xl font-light text-[#0C1825]">
              Informations Personnelles & Civilité
            </h1>
            <p className="text-xs text-[#3D5166] mt-1">
              Renseignez vos coordonnées officielles telles qu&apos;inscrites sur votre pièce d&apos;identité.
            </p>
          </div>

          <ErrorAlert message={error} fields={fieldErrors} />

          <form onSubmit={handleSubmit} className="space-y-6">
            <fieldset disabled={locked} className="space-y-5 disabled:opacity-60">
              {/* Civilité */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="space-y-1.5">
                  <label htmlFor="civilite" className="text-xs font-semibold text-[#0C1825]">
                    Civilité <span className="text-[#C0272D]">*</span>
                  </label>
                  <select
                    id="civilite"
                    value={form.civilite}
                    onChange={(e) => setForm({ ...form, civilite: e.target.value })}
                    className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3 py-2.5 text-[#0C1825] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]"
                    required
                  >
                    <option value="">Sélectionner</option>
                    <option value="M">M.</option>
                    <option value="Mme">Mme</option>
                    <option value="Mlle">Mlle</option>
                  </select>
                </div>

                <div className="space-y-1.5 sm:col-span-2">
                  <label htmlFor="etat_civil" className="text-xs font-semibold text-[#0C1825]">
                    État civil <span className="text-[#C0272D]">*</span>
                  </label>
                  <select
                    id="etat_civil"
                    value={form.etat_civil}
                    onChange={(e) => setForm({ ...form, etat_civil: e.target.value })}
                    className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3 py-2.5 text-[#0C1825] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]"
                    required
                  >
                    <option value="">Sélectionner</option>
                    <option value="célibataire">Célibataire</option>
                    <option value="marié">Marié(e)</option>
                    <option value="divorcé">Divorcé(e)</option>
                    <option value="veuf">Veuf / Veuve</option>
                  </select>
                </div>
              </div>

              {/* Nom & Prénom */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormInput
                  label="Nom"
                  id="nom"
                  value={form.nom}
                  onChange={(v) => setForm({ ...form, nom: v })}
                  placeholder="Ben Salah"
                  required
                />
                <FormInput
                  label="Prénom"
                  id="prenom"
                  value={form.prenom}
                  onChange={(v) => setForm({ ...form, prenom: v })}
                  placeholder="Mohamed"
                  required
                />
              </div>

              {/* Nom d'époux & 2ème prénom */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormInput
                  label="Nom d'époux (si applicable)"
                  id="nom_epoux"
                  value={form.nom_epoux ?? ''}
                  onChange={(v) => setForm({ ...form, nom_epoux: v })}
                  optional
                />
                <FormInput
                  label="Deuxième prénom"
                  id="deuxieme_prenom"
                  value={form.deuxieme_prenom ?? ''}
                  onChange={(v) => setForm({ ...form, deuxieme_prenom: v })}
                  optional
                />
              </div>

              {/* Naissance */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormInput
                  label="Date de naissance"
                  id="date_naissance"
                  type="date"
                  value={form.date_naissance}
                  onChange={(v) => setForm({ ...form, date_naissance: v })}
                  required
                />
                <FormInput
                  label="Lieu de naissance"
                  id="lieu_naissance"
                  value={form.lieu_naissance}
                  onChange={(v) => setForm({ ...form, lieu_naissance: v })}
                  placeholder="Tunis"
                  required
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormInput
                  label="Pays de naissance"
                  id="pays_naissance"
                  value={form.pays_naissance}
                  onChange={(v) => setForm({ ...form, pays_naissance: v })}
                  placeholder="Tunisie"
                  required
                />
                <FormInput
                  label="Nationalité"
                  id="nationalite"
                  value={form.nationalite}
                  onChange={(v) => setForm({ ...form, nationalite: v })}
                  placeholder="Tunisienne"
                  required
                />
              </div>

              {/* Résidence & Enfants */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormInput
                  label="Pays de résidence"
                  id="pays_residence"
                  value={form.pays_residence}
                  onChange={(v) => setForm({ ...form, pays_residence: v })}
                  placeholder="Tunisie"
                  required
                />
                <FormInput
                  label="Nombre d'enfants à charge"
                  id="nombre_enfants"
                  type="number"
                  value={String(form.nombre_enfants)}
                  onChange={(v) => setForm({ ...form, nombre_enfants: Number(v) })}
                />
              </div>

              {/* Pièce d'identité */}
              <div className="p-4 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] space-y-4">
                <div className="text-xs font-bold uppercase tracking-wider text-[#0C1825]">
                  Pièce d&apos;Identité Officielle
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="space-y-1.5">
                    <label htmlFor="type_pid" className="text-xs font-semibold text-[#0C1825]">
                      Type de pièce <span className="text-[#C0272D]">*</span>
                    </label>
                    <select
                      id="type_pid"
                      value={form.type_pid}
                      onChange={(e) => setForm({ ...form, type_pid: e.target.value })}
                      className="w-full text-xs font-medium bg-white border border-[#E0E4E9] rounded-lg px-3 py-2.5 text-[#0C1825] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]"
                      required
                    >
                      <option value="">Sélectionner</option>
                      <option value="CIN">Carte d&apos;Identité Nationale (CIN)</option>
                      <option value="Passeport">Passeport</option>
                      <option value="Carte de séjour">Carte de séjour</option>
                    </select>
                  </div>
                  <FormInput
                    label="Numéro de pièce"
                    id="numero_pid"
                    value={form.numero_pid}
                    onChange={(v) => setForm({ ...form, numero_pid: v })}
                    placeholder="08123456"
                    required
                  />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <FormInput
                    label="Date de délivrance"
                    id="date_delivrance_pid"
                    type="date"
                    value={form.date_delivrance_pid}
                    onChange={(v) => setForm({ ...form, date_delivrance_pid: v })}
                    required
                  />
                  <FormInput
                    label="Lieu de délivrance"
                    id="lieu_delivrance_pid"
                    value={form.lieu_delivrance_pid}
                    onChange={(v) => setForm({ ...form, lieu_delivrance_pid: v })}
                    placeholder="Tunis"
                    required
                  />
                </div>
              </div>

              {/* Profession */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormInput
                  label="Profession actuelle"
                  id="profession"
                  value={form.profession}
                  onChange={(v) => setForm({ ...form, profession: v })}
                  placeholder="Commerçant, Artisan, Ingénieur..."
                  required
                />
                <FormInput
                  label="Date d'entrée en relation (approximative)"
                  id="date_entree_relation"
                  type="date"
                  value={form.date_entree_relation}
                  onChange={(v) => setForm({ ...form, date_entree_relation: v })}
                />
              </div>
            </fieldset>

            {!locked && (
              <div className="pt-4 border-t border-[#E0E4E9] flex justify-end">
                <button
                  type="submit"
                  disabled={submitting}
                  className="btn-red text-xs inline-flex items-center gap-2 shadow-xs"
                  style={{ padding: '10px 24px' }}
                >
                  <span>{submitting ? 'Enregistrement…' : 'Étape suivante : Demande de crédit'}</span>
                  <ArrowRight className="size-4" />
                </button>
              </div>
            )}
          </form>
        </div>

        {/* Documents Upload Section */}
        <DocumentsUploadCard
          applicationId={applicationId}
          documents={application.documents}
          locked={locked}
        />
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
