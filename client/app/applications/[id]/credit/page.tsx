'use client';

import { useEffect, useState, type FormEvent } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { ApplicationStepper } from '@/components/application-stepper';
import { Breadcrumbs, BackLink } from '@/components/breadcrumbs';
import { CreditFinancingUploadCard } from '@/components/credit-financing-upload-card';
import { ArrowRight, Lock } from 'lucide-react';
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
  montant_eqp: '',
  montant_fdr: '',
  montant_amg: '',
  montant_chp: '',
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
            montant_eqp: application.credit_request.montant_eqp ?? '',
            montant_fdr: application.credit_request.montant_fdr ?? '',
            montant_amg: application.credit_request.montant_amg ?? '',
            montant_chp: application.credit_request.montant_chp ?? '',
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

    const globalVal = Number(form.montant_global_sollicite) || 0;
    const eqp = Number(form.montant_eqp) || 0;
    const fdr = Number(form.montant_fdr) || 0;
    const amg = Number(form.montant_amg) || 0;
    const chp = Number(form.montant_chp) || 0;
    const sum = eqp + fdr + amg + chp;

    if (sum > 0 && Math.abs(sum - globalVal) > 0.01) {
      setError(`La somme des financements détaillés (${sum.toLocaleString('fr-FR', { minimumFractionDigits: 3 })} TND) doit être exactement égale au montant global sollicité (${globalVal.toLocaleString('fr-FR', { minimumFractionDigits: 3 })} TND).`);
      return;
    }

    setSubmitting(true);
    try {
      const { application } = await updateCreditRequest(applicationId, {
        ...form,
        montant_global_sollicite: String(form.montant_global_sollicite),
        montant_eqp: form.montant_eqp ? String(form.montant_eqp) : '0',
        montant_fdr: form.montant_fdr ? String(form.montant_fdr) : '0',
        montant_amg: form.montant_amg ? String(form.montant_amg) : '0',
        montant_chp: form.montant_chp ? String(form.montant_chp) : '0',
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

        {/* ── Form Container wrapping Form & Document Upload ── */}
        <form onSubmit={handleSubmit} className="space-y-6">
          {/* ── 1. Form Card : Demande de Crédit & Montants ── */}
          <div className="figma-card p-6 sm:p-8 bg-white space-y-6 shadow-xs border border-[#E0E4E9]">
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
                    <option value="">Sélectionner</option>
                    <option value="crédit de création">Crédit de Création d&apos;Entreprise</option>
                    <option value="crédit d'extension">Crédit d&apos;Extension d&apos;Activité</option>
                    <option value="crédit de roulement">Fonds de Roulement</option>
                    <option value="crédit personnel">Crédit Professionnel / Artisan</option>
                  </select>
                </div>
                <FormInput
                  label="Origine du dossier"
                  id="origine"
                  value={form.origine}
                  onChange={(v) => setForm({ ...form, origine: v })}
                  placeholder="Portail en ligne"
                  required
                />
                <FormInput
                  label="Unité de dépôt (Agence BTS)"
                  id="unite_depot"
                  value={form.unite_depot}
                  onChange={(v) => setForm({ ...form, unite_depot: v })}
                  placeholder="Agence Tunis Belvédère"
                  required
                />
              </div>

              {/* Nom / Raison Sociale & Prénom */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormInput
                  label="Nom ou Raison Sociale"
                  id="nom_ou_rs"
                  value={form.nom_ou_rs}
                  onChange={(v) => setForm({ ...form, nom_ou_rs: v })}
                  placeholder="Ben Salah ou Société Exemple"
                  required
                />
                <FormInput
                  label="Prénom ou Dénomination Complémentaire"
                  id="prenom_ou_dc"
                  value={form.prenom_ou_dc}
                  onChange={(v) => setForm({ ...form, prenom_ou_dc: v })}
                  placeholder="Karim ou SARL"
                  required
                />
              </div>

              {/* Paramètres Financiers */}
              <div className="p-4 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] space-y-4">
                <div className="text-xs font-bold uppercase tracking-wider text-[#0C1825]">
                  Montant Global Sollicité &amp; Paramètres
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <FormInput
                    label="Montant Total Demandé"
                    id="montant_global_sollicite"
                    type="number"
                    value={String(form.montant_global_sollicite)}
                    onChange={(v) => setForm({ ...form, montant_global_sollicite: v })}
                    placeholder="Ex: 50000"
                    required
                  />
                  <div className="space-y-1.5">
                    <label htmlFor="code_devise" className="text-xs font-semibold text-[#0C1825]">
                      Devise <span className="text-[#C0272D]">*</span>
                    </label>
                    <input
                      id="code_devise"
                      type="text"
                      value={form.code_devise}
                      disabled
                      className="w-full text-xs font-mono font-bold bg-gray-100 border border-[#E0E4E9] rounded-lg px-3 py-2.5 text-[#0C1825] cursor-not-allowed"
                    />
                  </div>
                  <FormInput
                    label="Nombre de crédits sollicités"
                    id="nombre_credits_sollicites"
                    type="number"
                    value={String(form.nombre_credits_sollicites)}
                    onChange={(v) => setForm({ ...form, nombre_credits_sollicites: Number(v) })}
                    required
                  />
                </div>
              </div>

              {/* Dates de Dépôt & Réception */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormInput
                  label="Date de dépôt"
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
          </div>

          {/* ── 2. Credit Financing Breakdown & Document Upload Section (EQP, FDR, AMG, CHP, Devis, Contrat de Location) ── */}
          <CreditFinancingUploadCard
            applicationId={applicationId}
            documents={application.documents}
            locked={locked}
            breakdown={{
              montant_global_sollicite: form.montant_global_sollicite,
              montant_eqp: form.montant_eqp ?? '',
              montant_fdr: form.montant_fdr ?? '',
              montant_amg: form.montant_amg ?? '',
              montant_chp: form.montant_chp ?? '',
            }}
            onChangeBreakdown={(newBreakdown) =>
              setForm((prev) => ({
                ...prev,
                ...(newBreakdown.montant_eqp !== undefined ? { montant_eqp: String(newBreakdown.montant_eqp) } : {}),
                ...(newBreakdown.montant_fdr !== undefined ? { montant_fdr: String(newBreakdown.montant_fdr) } : {}),
                ...(newBreakdown.montant_amg !== undefined ? { montant_amg: String(newBreakdown.montant_amg) } : {}),
                ...(newBreakdown.montant_chp !== undefined ? { montant_chp: String(newBreakdown.montant_chp) } : {}),
              }))
            }
          />

          {/* ── 3. Bottom Action Bar (PLACED AFTER THE JOINDRE ZONE) ── */}
          {!locked && (
            <div className="figma-card p-4 sm:p-5 bg-white border border-[#E0E4E9] flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-xs rounded-xl">
              <div className="text-xs text-[#3D5166]">
                Assurez-vous que la répartition financière correspond au montant sollicité et que vos devis sont joints.
              </div>
              <button
                type="submit"
                disabled={submitting}
                className="btn-red shrink-0 justify-center gap-2 px-7 text-xs shadow-xs"
              >
                <span>{submitting ? 'Enregistrement…' : 'Étape suivante : Descriptif du projet'}</span>
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
