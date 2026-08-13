import Link from 'next/link';
import { ShieldCheck, Smartphone, Clock } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Logo } from '@/components/logo';
import { cn } from '@/lib/utils';

const FEATURES = [
  {
    icon: Clock,
    title: 'Apply in minutes',
    description: 'A guided, four-step application you can finish from your phone or computer.',
  },
  {
    icon: Smartphone,
    title: 'Phone-verified accounts',
    description: 'Every sign-in is confirmed with a one-time code sent to your phone.',
  },
  {
    icon: ShieldCheck,
    title: 'Secure by design',
    description: 'Your credentials and application data are protected at every step.',
  },
];

export default function Home() {
  return (
    <main className="relative flex min-h-screen flex-col overflow-hidden">
      <div
        aria-hidden
        className="pointer-events-none absolute -top-40 -right-40 h-96 w-96 rounded-full bg-primary/10 blur-3xl"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute top-1/2 -left-40 h-96 w-96 rounded-full bg-primary/5 blur-3xl"
      />

      <header className="relative flex items-center justify-between px-6 py-6 sm:px-10">
        <div className="flex items-center gap-2.5">
          <Logo size={32} />
          <span className="text-base font-semibold tracking-tight">
            BTS <span className="font-normal text-muted-foreground">Bank</span>
          </span>
        </div>
        <Link href="/login" className={cn(buttonVariants({ variant: 'ghost' }), 'h-9 px-4')}>
          Log in
        </Link>
      </header>

      <section className="relative mx-auto flex w-full max-w-3xl flex-1 flex-col items-center justify-center gap-8 px-6 py-16 text-center">
        <Logo size={72} />
        <div className="space-y-4">
          <h1 className="text-hero-title">
            Credit applications, <span className="text-primary">simplified</span>
          </h1>
          <p className="mx-auto max-w-lg text-lg text-muted-foreground">
            Register, verify your phone, and apply for credit online — then track your application every step of
            the way.
          </p>
        </div>
        <div className="flex flex-wrap items-center justify-center gap-4">
          <Link href="/register" className={cn(buttonVariants({ variant: 'default' }), 'h-11 px-8 text-base')}>
            Create an account
          </Link>
          <Link href="/login" className={cn(buttonVariants({ variant: 'outline' }), 'h-11 px-8 text-base')}>
            Log in
          </Link>
        </div>
      </section>

      <section className="relative mx-auto grid w-full max-w-4xl grid-cols-1 gap-4 px-6 pb-20 sm:grid-cols-3">
        {FEATURES.map(({ icon: Icon, title, description }) => (
          <Card key={title} className="card-surface-flat text-left">
            <CardContent className="space-y-2 pt-2">
              <div className="flex size-9 items-center justify-center rounded-lg bg-accent">
                <Icon className="size-4.5 text-accent-foreground" />
              </div>
              <h3 className="font-semibold">{title}</h3>
              <p className="text-sm text-muted-foreground">{description}</p>
            </CardContent>
          </Card>
        ))}
      </section>
    </main>
  );
}
