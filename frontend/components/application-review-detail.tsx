'use client';

import { useState } from 'react';
import { AlertTriangle, CircleCheck, CircleHelp, CircleX } from 'lucide-react';
import { ErrorAlert } from '@/components/error-alert';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { StaffApplicationDto } from '@/lib/api/staff';

/**
 * Shared review UI for both the staff and admin decision screens — same data, same layout, only
 * the allowed transition (and which endpoint fires) differs, which the parent page supplies via
 * onApprove/onReject.
 *
 * `canDecide` is passed in by the parent rather than derived from `application.status` here —
 * status.endsWith('APPROVED') would match STAFF_APPROVED as well as APPROVED, which on the admin
 * screen means exactly the application admin is supposed to act on next. "Can I still decide"
 * depends on which screen this is (staff acts on SUBMITTED, admin acts on STAFF_APPROVED), not
 * just on the status string — caught live: the admin screen hid its own Approve/Reject buttons
 * on every application staff had approved, showing "already decided" instead.
 */
export function ApplicationReviewDetail({
  application,
  canDecide,
  onApprove,
  onReject,
  working,
  error,
}: {
  application: StaffApplicationDto;
  canDecide: boolean;
  onApprove: () => void;
  onReject: (reason: string) => void;
  working: boolean;
  error: string | null;
}) {
  const [rejectOpen, setRejectOpen] = useState(false);
  const [reason, setReason] = useState('');

  return (
    <div className="space-y-6">
      <ErrorAlert message={error} />

      <Card className="card-surface">
        <CardHeader>
          <CardTitle>Applicant</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
          <SummaryRow label="Name" value={application.applicant?.name ?? '—'} />
          <SummaryRow label="Email" value={application.applicant?.email ?? '—'} />
          <SummaryRow label="Phone" value={application.applicant?.phone ?? '—'} />
          <SummaryRow label="N° Demande" value={application.credit_request?.n_demande ?? '—'} />
        </CardContent>
      </Card>

      {application.client && (
        <Card className="card-surface">
          <CardHeader>
            <CardTitle>Client</CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
            <SummaryRow label="Nom" value={`${application.client.prenom} ${application.client.nom}`} />
            <SummaryRow label="Code client" value={application.client.code_client} />
            <SummaryRow label="Type de pièce" value={`${application.client.type_pid} — ${application.client.numero_pid}`} />
            <SummaryRow label="Profession" value={application.client.profession} />
          </CardContent>
        </Card>
      )}

      {application.credit_request && (
        <Card className="card-surface">
          <CardHeader>
            <CardTitle>Demande de Crédit</CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
            <SummaryRow label="Type" value={application.credit_request.type_demande} />
            <SummaryRow
              label="Montant sollicité"
              value={`${application.credit_request.montant_global_sollicite} ${application.credit_request.code_devise}`}
            />
          </CardContent>
        </Card>
      )}

      {application.project && (
        <Card className="card-surface">
          <CardHeader>
            <CardTitle>Projet</CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
            <SummaryRow label="Type" value={application.project.type_projet} />
            <SummaryRow label="Coût" value={application.project.cout} />
            <SummaryRow label="Financement" value={application.project.financement} />
            <SummaryRow label="Revenus" value={application.project.revenus} />
          </CardContent>
        </Card>
      )}

      {application.documents && application.documents.length > 0 && (
        <Card className="card-surface">
          <CardHeader>
            <CardTitle>Documents</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="space-y-2 text-sm">
              {application.documents.map((doc) => (
                <li key={doc.id} className="space-y-0.5">
                  <div className="flex items-center justify-between gap-2">
                    <span className="flex items-center gap-1.5 text-muted-foreground">
                      <AiVerificationIcon isValid={doc.ai_is_valid} verifiedAt={doc.ai_verified_at} />
                      {doc.document_type}
                    </span>
                    <span>{doc.original_filename}</span>
                  </div>
                  {doc.ai_comment && (
                    <p className="pl-5 text-xs text-muted-foreground">
                      AI ({doc.ai_confidence} confidence): {doc.ai_comment}
                    </p>
                  )}
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}

      {!canDecide ? (
        <Alert className={application.status.endsWith('REJECTED') ? 'border-destructive/30 bg-destructive/5' : 'border-primary/30 bg-primary/5'}>
          <AlertDescription>
            Decision recorded: <Badge className="ml-1">{application.status}</Badge>
            {application.rejection_reason && (
              <p className="mt-2 text-sm text-muted-foreground">Reason: {application.rejection_reason}</p>
            )}
          </AlertDescription>
        </Alert>
      ) : (
        <div className="flex gap-3">
          <Button onClick={onApprove} disabled={working} className="flex-1">
            {working ? 'Working…' : 'Approve'}
          </Button>
          <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
            <DialogTrigger render={<Button variant="outline" disabled={working} className="flex-1">Reject</Button>} />
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Reject this application?</DialogTitle>
                <DialogDescription>Give a reason — this is recorded on the application.</DialogDescription>
              </DialogHeader>
              <div className="space-y-2">
                <Label htmlFor="reason">Reason</Label>
                <Textarea id="reason" value={reason} onChange={(e) => setReason(e.target.value)} rows={4} />
              </div>
              <DialogFooter>
                <Button variant="outline" onClick={() => setRejectOpen(false)}>
                  Cancel
                </Button>
                <Button
                  variant="destructive"
                  disabled={working || reason.trim().length < 3}
                  onClick={() => {
                    onReject(reason);
                    setRejectOpen(false);
                  }}
                >
                  <AlertTriangle className="size-4" />
                  {working ? 'Rejecting…' : 'Confirm rejection'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </div>
      )}
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

/** AI authenticity check status — advisory only, so "not yet checked" is a neutral icon, not an error. */
function AiVerificationIcon({ isValid, verifiedAt }: { isValid: boolean | null; verifiedAt: string | null }) {
  if (!verifiedAt) {
    return <CircleHelp className="size-3.5 shrink-0 text-muted-foreground/50" />;
  }
  if (isValid) {
    return <CircleCheck className="size-3.5 shrink-0 text-emerald-600" />;
  }
  return <CircleX className="size-3.5 shrink-0 text-destructive" />;
}
