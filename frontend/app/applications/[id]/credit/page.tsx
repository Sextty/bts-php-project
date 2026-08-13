'use client';

import { useEffect, useState, type FormEvent } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { ApplicationStepper } from '@/components/application-stepper';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  getApplication,
  updateCreditRequest,
  type CreditRequestDto,
  type CreditApplicationDto,
} from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { PageLoading } from '@/components/page-loading';

type FormState = Omit<CreditRequestDto, 'n_demande'>;

const EMPTY_FORM: FormState = {
  identifiant_personne: '',
  nom_ou_rs: '',
  prenom_ou_dc: '',
  type_pid: '',
  numero_pid: '',
  origine: '',
  date_depot: '',
  date_reception: '',
  type_demande: '',
  code_devise: '',
  montant_global_sollicite: '',
  nombre_credits_sollicites: 1,
  unite_depot: '',
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

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }
    getApplication(applicationId)
      .then(({ application }) => {
        setApplication(application);
        if (application.credit_request) setForm({ ...EMPTY_FORM, ...application.credit_request });
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Something went wrong.'))
      .finally(() => setLoading(false));
  }, [applicationId, router]);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
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
      setError(err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setSubmitting(false);
    }
  }

  if (loading) {
    return <PageLoading />;
  }
  if (!application) return null;

  const locked = application.is_locked;

  return (
    <div className="min-h-screen bg-muted/30">
      <DashboardHeader />
      <main className="mx-auto max-w-2xl px-4 py-10">
        <div className="mb-8">
          <ApplicationStepper status={application.status} current="credit" />
        </div>

        <Card className="card-surface">
          <CardHeader>
            <CardTitle>Demande de Crédit</CardTitle>
            {application.credit_request?.n_demande && (
              <p className="text-sm text-muted-foreground">
                N° Demande: <span className="font-medium text-foreground">{application.credit_request.n_demande}</span>
              </p>
            )}
          </CardHeader>
          <CardContent>
            <ErrorAlert message={error} />
            <form onSubmit={handleSubmit} className="space-y-4">
              <fieldset disabled={locked} className="space-y-4 disabled:opacity-60">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Identifiant personne" id="identifiant_personne" value={form.identifiant_personne} onChange={(v) => setForm({ ...form, identifiant_personne: v })} />
                  <Field label="Origine" id="origine" value={form.origine} onChange={(v) => setForm({ ...form, origine: v })} />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Nom ou RS" id="nom_ou_rs" value={form.nom_ou_rs} onChange={(v) => setForm({ ...form, nom_ou_rs: v })} />
                  <Field label="Prénom ou DC" id="prenom_ou_dc" value={form.prenom_ou_dc} onChange={(v) => setForm({ ...form, prenom_ou_dc: v })} />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Type de pièce" id="type_pid" value={form.type_pid} onChange={(v) => setForm({ ...form, type_pid: v })} />
                  <Field label="N° pièce" id="numero_pid" value={form.numero_pid} onChange={(v) => setForm({ ...form, numero_pid: v })} />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Date de dépôt" id="date_depot" type="date" value={form.date_depot} onChange={(v) => setForm({ ...form, date_depot: v })} />
                  <Field label="Date de réception" id="date_reception" type="date" value={form.date_reception} onChange={(v) => setForm({ ...form, date_reception: v })} />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Type de demande" id="type_demande" value={form.type_demande} onChange={(v) => setForm({ ...form, type_demande: v })} />
                  <Field label="Code devise" id="code_devise" value={form.code_devise} onChange={(v) => setForm({ ...form, code_devise: v.toUpperCase() })} />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Montant global sollicité" id="montant_global_sollicite" type="number" value={String(form.montant_global_sollicite)} onChange={(v) => setForm({ ...form, montant_global_sollicite: v })} />
                  <Field label="Nombre crédits sollicités" id="nombre_credits_sollicites" type="number" value={String(form.nombre_credits_sollicites)} onChange={(v) => setForm({ ...form, nombre_credits_sollicites: Number(v) })} />
                </div>
                <Field label="Unité de dépôt" id="unite_depot" value={form.unite_depot} onChange={(v) => setForm({ ...form, unite_depot: v })} />
              </fieldset>

              {!locked && (
                <Button type="submit" className="w-full" disabled={submitting}>
                  {submitting ? 'Enregistrement…' : 'Enregistrer et continuer'}
                </Button>
              )}
            </form>
          </CardContent>
        </Card>
      </main>
    </div>
  );
}

function Field({
  label,
  id,
  value,
  onChange,
  type = 'text',
}: {
  label: string;
  id: string;
  value: string;
  onChange: (value: string) => void;
  type?: string;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>
        {label}
        <span className="ml-0.5 text-primary">*</span>
      </Label>
      <Input id={id} type={type} required value={value} onChange={(e) => onChange(e.target.value)} />
    </div>
  );
}
