import { Loader2 } from 'lucide-react';

/** Full-page centered loading state — replaces bare "Loading…" text everywhere a page awaits its first fetch. */
export function PageLoading() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-3 bg-muted/30 text-muted-foreground">
      <Loader2 className="size-6 animate-spin text-primary" />
      <p className="text-sm">Loading…</p>
    </div>
  );
}

/** Inline loading state for a section already inside a page shell (e.g. a list below a header). */
export function InlineLoading() {
  return (
    <div className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
      <Loader2 className="size-4 animate-spin" />
      Loading…
    </div>
  );
}
