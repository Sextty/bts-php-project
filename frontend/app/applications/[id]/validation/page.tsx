'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { CheckCircle2, Lock, ShieldAlert } from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
import { ErrorAlert } from '@/components/error-alert';
import { ApplicationStepper } from '@/components/application-stepper';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import {
  getApplication,
  runValidationOne,
  confirmValidationTwo,
  submitApplication,
  type CreditApplicationDto,
} from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { PageLoading } from '@/components/page-loading';

export default function ValidationStepPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [application, setApplication] = useState<CreditApplicationDto | null>(null);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [validationErrors, setValidationErrors] = useState<string[] | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }
    getApplication(applicationId)
      .then(({ application }) => setApplication(application))
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Something went wrong.'))
      .finally(() => setLoading(false));
  }, [applicationId, router]);

  async function handleValidationOne() {
    setError(null);
    setValidationErrors(null);
    setWorking(true);
    try {
      const { application } = await runValidationOne(applicationId);
      setApplication(application);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setValidationErrors(err.errors);
      } else if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError('Something went wrong.');
      }
    } finally {
      setWorking(false);
    }
  }

  async function handleConfirmLock() {
    setError(null);
    setWorking(true);
    try {
      const { application } = await confirmValidationTwo(applicationId);
      setApplication(application);
      setConfirmOpen(false);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setWorking(false);
    }
  }

  async function handleSubmit() {
    setError(null);
    setWorking(true);
    try {
      const { application } = await submitApplication(applicationId);
      setApplication(application);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setWorking(false);
    }
  }

  if (loading) {
    return <PageLoading />;
  }
  if (!application) return null;

  const readyForValidation =
    application.client && application.credit_request && application.project;

  return (
    <div className="min-h-screen bg-muted/30">
      <DashboardHeader />
      <main className="mx-auto max-w-2xl px-4 py-10">
        <div className="mb-8">
          <ApplicationStepper status={application.status} current="validation" />
        </div>

        <ErrorAlert message={error} />

        {!readyForValidation ? (
          <Card className="card-surface">
            <CardContent className="space-y-3 py-12 text-center text-sm text-muted-foreground">
              <p>Complete the Client, Crédit, and Projet steps before validating.</p>
              <Link href={`/applications/${applicationId}/client`} className="font-medium text-primary underline">
                Go to Étape 1
              </Link>
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-6">
            <Card className="card-surface">
              <CardHeader>
                <CardTitle>Client</CardTitle>
              </CardHeader>
              <CardContent className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                <SummaryRow label="Nom" value={`${application.client!.prenom} ${application.client!.nom}`} />
                <SummaryRow label="Code client" value={application.client!.code_client} />
                <SummaryRow label="Type de pièce" value={`${application.client!.type_pid} — ${application.client!.numero_pid}`} />
                <SummaryRow label="Profession" value={application.client!.profession} />
              </CardContent>
            </Card>

            <Card className="card-surface">
              <CardHeader>
                <CardTitle>Demande de Crédit</CardTitle>
              </CardHeader>
              <CardContent className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                <SummaryRow label="N° Demande" value={application.credit_request!.n_demande} />
                <SummaryRow label="Type" value={application.credit_request!.type_demande} />
                <SummaryRow
                  label="Montant sollicité"
                  value={`${application.credit_request!.montant_global_sollicite} ${application.credit_request!.code_devise}`}
                />
              </CardContent>
            </Card>

            <Card className="card-surface">
              <CardHeader>
                <CardTitle>Projet</CardTitle>
              </CardHeader>
              <CardContent className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                <SummaryRow label="Code projet" value={application.project!.code_projet} />
                <SummaryRow label="Type" value={application.project!.type_projet} />
                <SummaryRow label="Coût" value={application.project!.cout} />
                <SummaryRow label="Financement" value={application.project!.financement} />
              </CardContent>
            </Card>

            <Card className="card-surface">
              <CardHeader>
                <CardTitle>Documents</CardTitle>
              </CardHeader>
              <CardContent>
                {application.documents && application.documents.length > 0 ? (
                  <ul className="space-y-1 text-sm">
                    {application.documents.map((doc) => (
                      <li key={doc.id} className="flex justify-between">
                        <span className="text-muted-foreground">{doc.document_type}</span>
                        <span>{doc.original_filename}</span>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="text-sm text-muted-foreground">No documents uploaded.</p>
                )}
              </CardContent>
            </Card>

            <Separator />

            {validationErrors && (
              <Alert variant="destructive">
                <ShieldAlert className="size-4" />
                <AlertDescription>
                  <ul className="list-inside list-disc space-y-1">
                    {validationErrors.map((e, i) => (
                      <li key={i}>{e}</li>
                    ))}
                  </ul>
                </AlertDescription>
              </Alert>
            )}

            {application.status === 'SUBMITTED' ? (
              <Alert className="border-primary/30 bg-primary/5">
                <CheckCircle2 className="size-4 text-primary" />
                <AlertDescription>This application has been submitted to BTS Bank.</AlertDescription>
              </Alert>
            ) : application.status === 'FINAL_LOCKED' ? (
              <div className="space-y-3">
                <Alert>
                  <Lock className="size-4" />
                  <AlertDescription>This application is finalized and can no longer be edited.</AlertDescription>
                </Alert>
                <Button onClick={handleSubmit} disabled={working} className="w-full">
                  {working ? 'Submitting…' : 'Submit application'}
                </Button>
              </div>
            ) : application.status === 'VALIDATION_1_COMPLETED' ? (
              <div className="space-y-3">
                <Alert className="border-primary/30 bg-primary/5">
                  <CheckCircle2 className="size-4 text-primary" />
                  <AlertDescription>Validation 1 passed. Review everything above before locking.</AlertDescription>
                </Alert>
                <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                  <DialogTrigger render={<Button className="w-full">Confirm and finalize</Button>} />
                  <DialogContent>
                    <DialogHeader>
                      <DialogTitle>Finalize this application?</DialogTitle>
                      <DialogDescription>
                        Once finalized, the client, credit, and project information — and all
                        uploaded documents — can no longer be changed.
                      </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                      <Button variant="outline" onClick={() => setConfirmOpen(false)}>
                        Cancel
                      </Button>
                      <Button onClick={handleConfirmLock} disabled={working}>
                        {working ? 'Finalizing…' : 'Yes, finalize'}
                      </Button>
                    </DialogFooter>
                  </DialogContent>
                </Dialog>
              </div>
            ) : (
              <Button onClick={handleValidationOne} disabled={working} className="w-full">
                {working ? 'Validating…' : 'Run validation'}
              </Button>
            )}

            {!application.is_locked && (
              <p className="text-center text-xs text-muted-foreground">
                Status: <Badge variant="outline">{application.status}</Badge>
              </p>
            )}
          </div>
        )}
      </main>
    </div>
  );
}

function SummaryRow({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="font-medium">{value}</p>
    </div>
  );
}
