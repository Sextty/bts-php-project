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
import { Textarea } from '@/components/ui/textarea';
import {
  getApplication,
  updateProject,
  type ProjectDto,
  type CreditApplicationDto,
} from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { PageLoading } from '@/components/page-loading';

const EMPTY_FORM: ProjectDto = {
  code_projet: '',
  identifiant_personne: '',
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
  const [form, setForm] = useState<ProjectDto>(EMPTY_FORM);
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
        if (application.project) setForm({ ...EMPTY_FORM, ...application.project });
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Something went wrong.'))
      .finally(() => setLoading(false));
  }, [applicationId, router]);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const { application } = await updateProject(applicationId, form);
      setApplication(application);
      router.push(`/applications/${applicationId}/validation`);
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
          <ApplicationStepper status={application.status} current="project" />
        </div>

        <Card className="card-surface">
          <CardHeader>
            <CardTitle>Informations Projet</CardTitle>
          </CardHeader>
          <CardContent>
            <ErrorAlert message={error} />
            <form onSubmit={handleSubmit} className="space-y-4">
              <fieldset disabled={locked} className="space-y-4 disabled:opacity-60">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Code projet" id="code_projet" value={form.code_projet} onChange={(v) => setForm({ ...form, code_projet: v })} />
                  <Field label="Type de projet" id="type_projet" value={form.type_projet} onChange={(v) => setForm({ ...form, type_projet: v })} />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Identifiant personne" id="identifiant_personne" value={form.identifiant_personne} onChange={(v) => setForm({ ...form, identifiant_personne: v })} />
                  <Field label="Activité" id="activite" value={form.activite} onChange={(v) => setForm({ ...form, activite: v })} />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Nom ou RS" id="nom_ou_rs" value={form.nom_ou_rs} onChange={(v) => setForm({ ...form, nom_ou_rs: v })} />
                  <Field label="Prénom ou DC" id="prenom_ou_dc" value={form.prenom_ou_dc} onChange={(v) => setForm({ ...form, prenom_ou_dc: v })} />
                </div>
                <Field label="Objet" id="objet" value={form.objet} onChange={(v) => setForm({ ...form, objet: v })} />
                <div className="space-y-2">
                  <Label htmlFor="description">
                    Description<span className="ml-0.5 text-primary">*</span>
                  </Label>
                  <Textarea
                    id="description"
                    required
                    value={form.description}
                    onChange={(e) => setForm({ ...form, description: e.target.value })}
                    rows={4}
                  />
                </div>
                <Field label="Adresse" id="adresse" value={form.adresse} onChange={(v) => setForm({ ...form, adresse: v })} />
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Ville" id="ville" value={form.ville} onChange={(v) => setForm({ ...form, ville: v })} />
                  <Field label="Code postal" id="code_postal" value={form.code_postal} onChange={(v) => setForm({ ...form, code_postal: v })} />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Délégation" id="delegation" value={form.delegation} onChange={(v) => setForm({ ...form, delegation: v })} />
                  <Field label="Localisation" id="localisation" value={form.localisation} onChange={(v) => setForm({ ...form, localisation: v })} />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Coût" id="cout" type="number" value={String(form.cout)} onChange={(v) => setForm({ ...form, cout: v })} />
                  <Field label="Investissement personnel" id="investissement_personnel" type="number" value={String(form.investissement_personnel)} onChange={(v) => setForm({ ...form, investissement_personnel: v })} />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="Financement" id="financement" type="number" value={String(form.financement)} onChange={(v) => setForm({ ...form, financement: v })} />
                  <Field label="Revenus" id="revenus" type="number" value={String(form.revenus)} onChange={(v) => setForm({ ...form, revenus: v })} />
                </div>
                <Field label="Dépenses" id="depenses" type="number" value={String(form.depenses)} onChange={(v) => setForm({ ...form, depenses: v })} />
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
