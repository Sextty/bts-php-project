import Link from 'next/link';
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
    <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-muted/40 p-4">
      <div
        aria-hidden
        className="pointer-events-none absolute -top-40 -right-40 h-96 w-96 rounded-full bg-primary/10 blur-3xl"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute -bottom-40 -left-40 h-96 w-96 rounded-full bg-primary/5 blur-3xl"
      />
      <div className="relative flex w-full max-w-md flex-col items-center gap-6">
        <Link href="/" className="flex items-center gap-2.5">
          <Logo size={40} />
          <span className="text-lg font-semibold tracking-tight text-foreground">
            BTS <span className="font-normal text-muted-foreground">Bank</span>
          </span>
        </Link>
        <Card className="card-surface w-full">
          <CardHeader>
            <CardTitle className="text-title-lg">{title}</CardTitle>
            {description && <CardDescription>{description}</CardDescription>}
          </CardHeader>
          <CardContent>{children}</CardContent>
        </Card>
      </div>
    </div>
  );
}
