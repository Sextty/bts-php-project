import Link from 'next/link';
import { CheckCircle2, LockKeyhole, ShieldCheck } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Logo } from '@/components/logo';

export function AuthCard({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: React.ReactNode;
}) {
  return (
    <main id="main" className="min-h-screen bg-[#f6f7f9] p-4 sm:p-6 lg:p-8">
      <div className="mx-auto grid min-h-[calc(100vh-2rem)] max-w-6xl overflow-hidden rounded-3xl border border-[#e2e7ec] bg-white shadow-[0_24px_80px_rgba(12,24,37,0.12)] lg:grid-cols-[0.9fr_1.1fr]">
        <aside className="relative hidden overflow-hidden bg-[#0c1825] p-10 text-white lg:flex lg:flex-col">
          <div aria-hidden className="absolute -right-24 -top-24 size-72 rounded-full border border-white/10" />
          <div aria-hidden className="absolute -bottom-24 -left-24 size-80 rounded-full bg-[#c0272d]/30 blur-3xl" />
          <Link href="/" className="relative flex w-fit items-center gap-3 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">
            <Logo size={42} />
            <span className="font-display text-xl font-semibold tracking-tight">BTS Bank</span>
          </Link>

          <div className="relative my-auto max-w-sm">
            <p className="mb-4 text-xs font-bold uppercase tracking-[0.18em] text-[#f6a3a6]">Espace client sécurisé</p>
            <h1 className="font-display text-4xl font-light leading-tight">Votre projet avance, étape par étape.</h1>
            <p className="mt-5 text-sm leading-6 text-slate-300">Créez, complétez et suivez votre dossier de financement depuis un seul espace personnel.</p>
          </div>

          <ul className="relative space-y-4 text-sm text-slate-200">
            {[
              'Vérification renforcée de votre identité',
              'Documents transmis sur un espace privé',
              'Suivi clair de chaque étape de votre dossier',
            ].map((item) => (
              <li key={item} className="flex gap-3">
                <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-[#f6a3a6]" />
                <span>{item}</span>
              </li>
            ))}
          </ul>
        </aside>

        <section className="flex min-h-full flex-col p-6 sm:p-10 lg:p-14">
          <div className="flex items-center justify-between lg:hidden">
            <Link href="/" className="flex items-center gap-2.5 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#c0272d]">
              <Logo size={38} />
              <span className="font-display text-lg font-semibold text-[#0c1825]">BTS Bank</span>
            </Link>
            <span className="inline-flex items-center gap-1.5 text-xs font-medium text-[#3d5166]"><LockKeyhole className="size-3.5" /> Espace privé</span>
          </div>

          <div className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center py-10 lg:py-0">
            <div className="mb-7">
              <div className="mb-4 inline-flex size-10 items-center justify-center rounded-xl bg-[#fdf2f2] text-[#c0272d]">
                <ShieldCheck className="size-5" aria-hidden="true" />
              </div>
              <h2 className="font-display text-3xl font-semibold tracking-tight text-[#0c1825]">{title}</h2>
              {description && <p className="mt-2 text-sm leading-6 text-[#3d5166]">{description}</p>}
            </div>

            <Card className="border-0 bg-transparent shadow-none">
              <CardHeader className="sr-only">
                <CardTitle>{title}</CardTitle>
                {description && <CardDescription>{description}</CardDescription>}
              </CardHeader>
              <CardContent className="p-0">{children}</CardContent>
            </Card>
          </div>
          <p className="text-center text-xs text-[#6a7a8b]">© {new Date().getFullYear()} BTS Bank · Vos données sont traitées de manière sécurisée.</p>
        </section>
      </div>
    </main>
  );
}
