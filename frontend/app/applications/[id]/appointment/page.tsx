'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { CalendarCheck2, CheckCircle2, Lock, MapPin, MessageCircle } from 'lucide-react';
import { DashboardHeader } from '@/components/dashboard-header';
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
import { getAppointment, acceptAppointment, rejectAppointment, type AppointmentDto } from '@/lib/api/appointments';
import { getApplication, type ApplicationStatus } from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import { PageLoading } from '@/components/page-loading';

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
}

function formatTime(timeStr: string) {
  return timeStr.slice(0, 5);
}

export default function AppointmentPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const applicationId = Number(params.id);

  const [applicationStatus, setApplicationStatus] = useState<ApplicationStatus | null>(null);
  const [appointment, setAppointment] = useState<AppointmentDto | null>(null);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [rejectOpen, setRejectOpen] = useState(false);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/login');
      return;
    }
    Promise.all([getApplication(applicationId), getAppointment(applicationId)])
      .then(([app, appt]) => {
        setApplicationStatus(app.application.status);
        setAppointment(appt.appointment);
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Something went wrong.'))
      .finally(() => setLoading(false));
  }, [applicationId, router]);

  async function handleAccept() {
    setError(null);
    setWorking(true);
    try {
      const { appointment } = await acceptAppointment(applicationId);
      setAppointment(appointment);
      setApplicationStatus('APPOINTMENT_CONFIRMED');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setWorking(false);
    }
  }

  async function handleReject() {
    setError(null);
    setWorking(true);
    try {
      const { application_status, appointment } = await rejectAppointment(applicationId);
      setApplicationStatus(application_status as ApplicationStatus);
      setAppointment(appointment);
      setRejectOpen(false);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setWorking(false);
    }
  }

  if (loading) {
    return <PageLoading />;
  }

  return (
    <div className="min-h-screen bg-muted/30">
      <DashboardHeader />
      <main className="mx-auto max-w-2xl px-4 py-10">
        <div className="mb-6">
          <h1 className="text-title">Your appointment</h1>
          <p className="text-sm text-muted-foreground">
            Your application has been approved. Confirm your appointment at the branch below.
          </p>
        </div>

        <ErrorAlert message={error} />

        {applicationStatus === 'APPOINTMENT_LOCKED' ? (
          <div className="space-y-3">
            <Alert>
              <Lock className="size-4" />
              <AlertDescription>
                You&apos;ve rejected the proposed time 3 times. A BTS Bank representative will contact
                you directly to arrange your appointment.
              </AlertDescription>
            </Alert>
            <Button onClick={() => router.push(`/applications/${applicationId}/report`)} className="w-full gap-1.5">
              <MessageCircle className="size-4" />
              Talk to BTS Bank
            </Button>
          </div>
        ) : applicationStatus === 'APPOINTMENT_CONFIRMED' && appointment ? (
          <div className="space-y-6">
            <Alert className="border-primary/30 bg-primary/5">
              <CheckCircle2 className="size-4 text-primary" />
              <AlertDescription>Your appointment is confirmed.</AlertDescription>
            </Alert>
            <AppointmentCard appointment={appointment} />
          </div>
        ) : applicationStatus === 'APPOINTMENT_PROPOSED' && appointment ? (
          <div className="space-y-6">
            <AppointmentCard appointment={appointment} />

            <p className="text-center text-xs text-muted-foreground">
              Attempt {appointment.attempt_number} of {appointment.max_attempts}
            </p>

            <div className="flex gap-3">
              <Button onClick={handleAccept} disabled={working} className="flex-1">
                {working ? 'Confirming…' : 'Accept this time'}
              </Button>
              <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
                <DialogTrigger render={<Button variant="outline" disabled={working} className="flex-1">Reject</Button>} />
                <DialogContent>
                  <DialogHeader>
                    <DialogTitle>Reject this appointment time?</DialogTitle>
                    <DialogDescription>
                      {appointment.attempt_number >= appointment.max_attempts
                        ? 'This is your last attempt — rejecting will lock the application and a staff member will contact you directly.'
                        : 'We’ll propose the next available time at the same branch.'}
                    </DialogDescription>
                  </DialogHeader>
                  <DialogFooter>
                    <Button variant="outline" onClick={() => setRejectOpen(false)}>
                      Cancel
                    </Button>
                    <Button variant="destructive" onClick={handleReject} disabled={working}>
                      {working ? 'Rejecting…' : 'Yes, reject'}
                    </Button>
                  </DialogFooter>
                </DialogContent>
              </Dialog>
            </div>
          </div>
        ) : (
          <Card className="card-surface-empty">
            <CardContent className="py-12 text-center text-sm text-muted-foreground">
              No appointment has been proposed yet.
            </CardContent>
          </Card>
        )}
      </main>
    </div>
  );
}

function AppointmentCard({ appointment }: { appointment: AppointmentDto }) {
  return (
    <Card className="card-surface">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <CalendarCheck2 className="size-4 text-primary" />
          {formatDate(appointment.scheduled_date)} at {formatTime(appointment.scheduled_time)}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {appointment.branch && (
          <div className="flex items-start justify-between gap-4 rounded-md border border-border/60 bg-muted/30 p-3">
            <div>
              <p className="font-medium">{appointment.branch.name}</p>
              <p className="text-sm text-muted-foreground">{appointment.branch.address}</p>
              <p className="text-sm text-muted-foreground">{appointment.branch.ville}</p>
            </div>
            <Badge variant="outline" className="shrink-0">
              {appointment.status}
            </Badge>
          </div>
        )}
        {appointment.branch && (
          <a href={appointment.branch.google_maps_url} target="_blank" rel="noopener noreferrer">
            <Button variant="outline" className="w-full gap-1.5">
              <MapPin className="size-4" />
              Open in Google Maps
            </Button>
          </a>
        )}
      </CardContent>
    </Card>
  );
}
