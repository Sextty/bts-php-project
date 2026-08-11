'use client';

import { useEffect, useState, type FormEvent } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { Trash2, Upload } from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { ApplicationStepper } from '@/components/application-stepper';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  getApplication,
  updateClient,
  uploadDocument,
  deleteDocument,
  type ClientDto,
  type CreditApplicationDto,
} from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';

const EMPTY_CLIENT: ClientDto = {
  code_client: '',
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

// Mirrors backend's config/credit_documents.php — kept as a small constant here since there's
// no endpoint exposing config to the frontend, and this list changes rarely.
const DOCUMENT_TYPES = [
  { key: 'cin', label: "Carte d'Identité Nationale", required: true },
  { key: 'passport', label: 'Passeport', required: false },
  { key: 'carte_sejour', label: 'Carte de Séjour', required: false },
  { key: 'other', label: 'Autre document', required: false },
];

export default function ClientStepPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [application, setApplication] = useState<CreditApplicationDto | null>(null);
  const [form, setForm] = useState<ClientDto>(EMPTY_CLIENT);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [uploading, setUploading] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

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
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Something went wrong.'))
      .finally(() => setLoading(false));
  }, [applicationId, router]);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const { application } = await updateClient(applicationId, form);
      setApplication(application);
      router.push(`/applications/${applicationId}/credit`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setSubmitting(false);
    }
  }

  async function handleUpload(documentType: string, file: File) {
    setError(null);
    setUploading(documentType);
    try {
      await uploadDocument(applicationId, documentType, file);
      const { application } = await getApplication(applicationId);
      setApplication(application);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Upload failed.');
    } finally {
      setUploading(null);
    }
  }

  async function handleDeleteDocument(documentId: number) {
    setError(null);
    try {
      await deleteDocument(applicationId, documentId);
      const { application } = await getApplication(applicationId);
      setApplication(application);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not remove the document.');
    }
  }

  if (loading) {
    return <div className="flex min-h-screen items-center justify-center text-muted-foreground">Loading…</div>;
  }
  if (!application) return null;

  const locked = application.is_locked;

  return (
    <div className="min-h-screen bg-muted/30">
      <DashboardHeader />
      <main className="mx-auto max-w-2xl px-4 py-10">
        <div className="mb-8">
          <ApplicationStepper status={application.status} current="client" />
        </div>

        <Card className="border-border/60 shadow-sm">
          <CardHeader>
            <CardTitle>Client — Personne Physique</CardTitle>
          </CardHeader>
          <CardContent>
            <ErrorAlert message={error} />
            <form onSubmit={handleSubmit} className="space-y-4">
              <fieldset disabled={locked} className="space-y-4 disabled:opacity-60">
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Code client" id="code_client" value={form.code_client} onChange={(v) => setForm({ ...form, code_client: v })} />
                  <div className="space-y-2">
                    <Label htmlFor="civilite">Civilité</Label>
                    <Select value={form.civilite} onValueChange={(v) => setForm({ ...form, civilite: v ?? '' })}>
                      <SelectTrigger id="civilite"><SelectValue placeholder="Sélectionner" /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="M">M</SelectItem>
                        <SelectItem value="Mme">Mme</SelectItem>
                        <SelectItem value="Mlle">Mlle</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Nom" id="nom" value={form.nom} onChange={(v) => setForm({ ...form, nom: v })} />
                  <Field label="Prénom" id="prenom" value={form.prenom} onChange={(v) => setForm({ ...form, prenom: v })} />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Nom d'époux" id="nom_epoux" value={form.nom_epoux ?? ''} onChange={(v) => setForm({ ...form, nom_epoux: v })} optional />
                  <Field label="2ème prénom" id="deuxieme_prenom" value={form.deuxieme_prenom ?? ''} onChange={(v) => setForm({ ...form, deuxieme_prenom: v })} optional />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Date de naissance" id="date_naissance" type="date" value={form.date_naissance} onChange={(v) => setForm({ ...form, date_naissance: v })} />
                  <Field label="Lieu de naissance" id="lieu_naissance" value={form.lieu_naissance} onChange={(v) => setForm({ ...form, lieu_naissance: v })} />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Pays de naissance" id="pays_naissance" value={form.pays_naissance} onChange={(v) => setForm({ ...form, pays_naissance: v })} />
                  <Field label="Nationalité" id="nationalite" value={form.nationalite} onChange={(v) => setForm({ ...form, nationalite: v })} />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Pays de résidence" id="pays_residence" value={form.pays_residence} onChange={(v) => setForm({ ...form, pays_residence: v })} />
                  <div className="space-y-2">
                    <Label htmlFor="etat_civil">État civil</Label>
                    <Select value={form.etat_civil} onValueChange={(v) => setForm({ ...form, etat_civil: v ?? '' })}>
                      <SelectTrigger id="etat_civil"><SelectValue placeholder="Sélectionner" /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="célibataire">Célibataire</SelectItem>
                        <SelectItem value="marié">Marié(e)</SelectItem>
                        <SelectItem value="divorcé">Divorcé(e)</SelectItem>
                        <SelectItem value="veuf">Veuf/Veuve</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>
                <Field label="Nombre d'enfants" id="nombre_enfants" type="number" value={String(form.nombre_enfants)} onChange={(v) => setForm({ ...form, nombre_enfants: Number(v) })} />
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="type_pid">Type de pièce</Label>
                    <Select value={form.type_pid} onValueChange={(v) => setForm({ ...form, type_pid: v ?? '' })}>
                      <SelectTrigger id="type_pid"><SelectValue placeholder="Sélectionner" /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="CIN">CIN</SelectItem>
                        <SelectItem value="Passeport">Passeport</SelectItem>
                        <SelectItem value="Carte de séjour">Carte de séjour</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <Field label="N° pièce" id="numero_pid" value={form.numero_pid} onChange={(v) => setForm({ ...form, numero_pid: v })} />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Date de délivrance" id="date_delivrance_pid" type="date" value={form.date_delivrance_pid} onChange={(v) => setForm({ ...form, date_delivrance_pid: v })} />
                  <Field label="Lieu de délivrance" id="lieu_delivrance_pid" value={form.lieu_delivrance_pid} onChange={(v) => setForm({ ...form, lieu_delivrance_pid: v })} />
                </div>
                <Field label="N° carte de séjour étrangère" id="numero_carte_sejour" value={form.numero_carte_sejour ?? ''} onChange={(v) => setForm({ ...form, numero_carte_sejour: v })} optional />
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Profession" id="profession" value={form.profession} onChange={(v) => setForm({ ...form, profession: v })} />
                  <Field label="Date d'entrée en relation" id="date_entree_relation" type="date" value={form.date_entree_relation} onChange={(v) => setForm({ ...form, date_entree_relation: v })} />
                </div>
              </fieldset>

              {!locked && (
                <Button type="submit" className="w-full" disabled={submitting}>
                  {submitting ? 'Enregistrement…' : 'Enregistrer et continuer'}
                </Button>
              )}
            </form>
          </CardContent>
        </Card>

        <Card className="mt-6 border-border/60 shadow-sm">
          <CardHeader>
            <CardTitle className="text-base">Documents justificatifs</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {DOCUMENT_TYPES.map((docType) => {
              const uploaded = application.documents?.filter((d) => d.document_type === docType.key) ?? [];
              return (
                <div key={docType.key} className="flex items-center justify-between rounded-lg border border-border/60 px-4 py-3">
                  <div>
                    <p className="text-sm font-medium">
                      {docType.label}
                      {docType.required && <span className="ml-1 text-primary">*</span>}
                    </p>
                    {uploaded.map((doc) => (
                      <div key={doc.id} className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                        <span>{doc.original_filename}</span>
                        {!locked && (
                          <button
                            type="button"
                            onClick={() => handleDeleteDocument(doc.id)}
                            className="text-destructive hover:underline"
                            aria-label={`Delete ${doc.original_filename}`}
                          >
                            <Trash2 className="size-3.5" />
                          </button>
                        )}
                      </div>
                    ))}
                  </div>
                  {!locked && (
                    <label className="inline-flex cursor-pointer items-center gap-1.5 text-sm font-medium text-primary">
                      <Upload className="size-4" />
                      {uploading === docType.key ? 'Envoi…' : 'Téléverser'}
                      <input
                        type="file"
                        className="hidden"
                        accept=".pdf,.jpg,.jpeg,.png"
                        disabled={uploading !== null}
                        onChange={(e) => {
                          const file = e.target.files?.[0];
                          if (file) handleUpload(docType.key, file);
                          e.target.value = '';
                        }}
                      />
                    </label>
                  )}
                </div>
              );
            })}
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
  optional = false,
}: {
  label: string;
  id: string;
  value: string;
  onChange: (value: string) => void;
  type?: string;
  optional?: boolean;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>
        {label}
        {!optional && <span className="ml-0.5 text-primary">*</span>}
      </Label>
      <Input id={id} type={type} required={!optional} value={value} onChange={(e) => onChange(e.target.value)} />
    </div>
  );
}
