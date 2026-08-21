'use client';

import { useEffect, useState, type FormEvent } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { ApplicationStepper } from '@/components/application-stepper';
import { Breadcrumbs, BackLink } from '@/components/breadcrumbs';
import { DocumentsUploadCard } from '@/components/documents-upload-card';
import { ArrowRight, Lock, CreditCard } from 'lucide-react';
import {
  getApplication,
  updateCreditRequest,
  type CreditRequestDto,
  type CreditApplicationDto,
} from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { PageLoading } from '@/components/page-loading';

type FormState = Omit<CreditRequestDto, 'n_demande' | 'identifiant_personne' | 'type_pid' | 'numero_pid'>;

const EMPTY_FORM: FormState = {
  nom_ou_rs: '',
  prenom_ou_dc: '',
  origine: 'Portail en ligne',
  date_depot: '',
  date_reception: '',
  type_demande: '',
  code_devise: 'TND',
  montant_global_sollicite: '',
  nombre_credits_sollicites: 1,
  unite_depot: 'Portail en ligne',
};

export default function CreditRequestStepPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [application, setApplication] = useState<CreditApplicationDto | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
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
        if (application.credit_request) {
          setForm({
            ...EMPTY_FORM,
            ...application.credit_request,
            unite_depot: application.credit_request.unite_depot || application.branch?.name || EMPTY_FORM.unite_depot,
          });
        } else {
          setForm((prev) => ({
            ...prev,
            nom_ou_rs: prev.nom_ou_rs || (application.client ? `${application.client.nom} ${application.client.prenom}`.trim() : ''),
            prenom_ou_dc: prev.prenom_ou_dc || application.client?.prenom || '',
            unite_depot: application.branch?.name || prev.unite_depot,
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
      const { application } = await updateCreditRequest(applicationId, {
        ...form,
        montant_global_sollicite: String(form.montant_global_sollicite),
        nombre_credits_sollicites: Number(form.nombre_credits_sollicites),
      });
      setApplication(application);
      router.push(`/applications/${applicationId}/project`);
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
            { label: 'Demande de Crédit' },
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
        <ApplicationStepper status={application.status} current="credit" />

        {/* ── Form Card ── */}
        <div className="figma-card p-6 sm:p-8 bg-white space-y-6">
          <div className="border-b border-[#E0E4E9] pb-4">
            <p className="overline">Étape 2 sur 5</p>
            <h1 className="font-display text-2xl font-light text-[#0C1825]">
              Demande de Crédit & Montants
            </h1>
            <p className="text-xs text-[#3D5166] mt-1">
              Précisez le type de financement souhaité et le montant global sollicité auprès de la BTS Bank.
            </p>
          </div>

          <ErrorAlert message={error} fields={fieldErrors} />

          <form onSubmit={handleSubmit} className="space-y-6">
            <fieldset disabled={locked} className="space-y-5 disabled:opacity-60">
              {/* Type de Demande, Origine & Unité de dépôt */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="space-y-1.5">
                  <label htmlFor="type_demande" className="text-xs font-semibold text-[#0C1825]">
                    Type de crédit sollicité <span className="text-[#C0272D]">*</span>
                  </label>
                  <select
                    id="type_demande"
                    value={form.type_demande}
                    onChange={(e) => setForm({ ...form, type_demande: e.target.value })}
                    className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3 py-2.5 text-[#0C1825] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]"
                    required
                  >
                    <option value="">Sélectionner un produit</option>
                    <option value="Crédit Professionnel">Crédit Professionnel</option>
                    <option value="Crédit d'investissement">Crédit d&apos;investissement</option>
                    <option value="Crédit de gestion">Crédit de gestion</option>
                    <option value="Fonds de roulement">Fonds de roulement</option>
                    <option value="Crédit TIC">Crédit TIC (Technologies de l&apos;information)</option>
                    <option value="Finance Islamique">Finance Islamique (Mourabaha / Ijara)</option>
                  </select>
                </div>

                <div className="space-y-1.5">
                  <label htmlFor="origine" className="text-xs font-semibold text-[#0C1825]">
                    Origine du dossier <span className="text-[#C0272D]">*</span>
                  </label>
                  <select
                    id="origine"
                    value={form.origine}
                    onChange={(e) => setForm({ ...form, origine: e.target.value })}
                    className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3 py-2.5 text-[#0C1825] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]"
                    required
                  >
                    <option value="">Sélectionner</option>
                    <option value="Portail en ligne">Portail en ligne BTS</option>
                    <option value="Agence">Dépôt en agence</option>
                    <option value="Partenaire">Organisme partenaire (APII, ODESYPANO)</option>
                  </select>
                </div>

                <FormInput
                  label="Unité de dépôt"
                  id="unite_depot"
                  value={form.unite_depot}
                  onChange={(v) => setForm({ ...form, unite_depot: v })}
                  placeholder="Ex: Agence Tunis, Portail en ligne..."
                  required
                />
              </div>

              {/* Montant, Devise & Nombre de crédits */}
              <div className="p-4 bg-[#FDF2F2] border border-[#FECACA] rounded-xl space-y-4">
                <div className="text-xs font-bold uppercase tracking-wider text-[#C0272D]">
                  Paramètres Financiers
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div className="space-y-1.5">
                    <label htmlFor="montant_global_sollicite" className="text-xs font-semibold text-[#0C1825]">
                      Montant global sollicité <span className="text-[#C0272D]">*</span>
                    </label>
                    <div className="relative">
                      <input
                        id="montant_global_sollicite"
                        type="number"
                        min="1000"
                        step="500"
                        required
                        placeholder="Ex: 50000"
                        value={form.montant_global_sollicite}
                        onChange={(e) => setForm({ ...form, montant_global_sollicite: e.target.value })}
                        className="w-full text-sm font-bold font-mono bg-white border border-[#E0E4E9] rounded-lg px-3 py-2.5 text-[#0C1825] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]"
                      />
                      <span className="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-[#3D5166]">
                        {form.code_devise || 'TND'}
                      </span>
                    </div>
                  </div>

                  <div className="space-y-1.5">
                    <label htmlFor="code_devise" className="text-xs font-semibold text-[#0C1825]">
                      Devise <span className="text-[#C0272D]">*</span>
                    </label>
                    <select
                      id="code_devise"
                      value={form.code_devise}
                      onChange={(e) => setForm({ ...form, code_devise: e.target.value })}
                      className="w-full text-xs font-medium bg-white border border-[#E0E4E9] rounded-lg px-3 py-2.5 text-[#0C1825] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]"
                      required
                    >
                      <option value="TND">TND (Dinar Tunisien)</option>
                    </select>
                  </div>

                  <div className="space-y-1.5">
                    <label htmlFor="nombre_credits_sollicites" className="text-xs font-semibold text-[#0C1825]">
                      Nombre de crédits sollicités <span className="text-[#C0272D]">*</span>
                    </label>
                    <input
                      id="nombre_credits_sollicites"
                      type="number"
                      min="1"
                      max="10"
                      required
                      value={form.nombre_credits_sollicites}
                      onChange={(e) => setForm({ ...form, nombre_credits_sollicites: Number(e.target.value) || 1 })}
                      className="w-full text-xs font-medium bg-white border border-[#E0E4E9] rounded-lg px-3 py-2.5 text-[#0C1825] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]"
                    />
                  </div>
                </div>
              </div>

              {/* Raison Sociale / Nom Entreprise */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormInput
                  label="Nom ou Raison Sociale de l'activité"
                  id="nom_ou_rs"
                  value={form.nom_ou_rs}
                  onChange={(v) => setForm({ ...form, nom_ou_rs: v })}
                  placeholder="Ex: Société Tunisienne de Services"
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

              {/* Dates de dépôt et réception */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormInput
                  label="Date de dépôt du dossier"
                  id="date_depot"
                  type="date"
                  value={form.date_depot}
                  onChange={(v) => setForm({ ...form, date_depot: v })}
                  required
                />
                <FormInput
                  label="Date de réception"
                  id="date_reception"
                  type="date"
                  value={form.date_reception}
                  onChange={(v) => setForm({ ...form, date_reception: v })}
                  required
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
                  <span>{submitting ? 'Enregistrement…' : 'Étape suivante : Descriptif du projet'}</span>
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
