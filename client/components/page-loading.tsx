import { Landmark, Loader2 } from 'lucide-react';

/** Full-page centered loading state — replaces bare "Loading…" text everywhere a page awaits its first fetch. */
export function PageLoading() {
  return (
    <div
      role="status"
      aria-live="polite"
      className="portal-shell flex min-h-screen flex-col items-center justify-center gap-4 text-[#536579]"
    >
      <div className="relative flex size-16 items-center justify-center rounded-2xl border border-[#e0e4e9] bg-white shadow-lg">
        <Landmark className="size-7 text-[#c0272d]" aria-hidden="true" />
        <Loader2 className="absolute -bottom-2 -right-2 size-6 animate-spin rounded-full bg-white p-1 text-[#c0272d] shadow-sm" aria-hidden="true" />
      </div>
      <div className="text-center">
        <p className="text-sm font-semibold text-[#1e2d3d]">Chargement de votre espace</p>
        <p className="mt-1 text-xs">Vos informations sécurisées arrivent…</p>
      </div>
      <span className="sr-only">Chargement</span>
    </div>
  );
}

/** Inline loading state for a section already inside a page shell (e.g. a list below a header). */
export function InlineLoading() {
  return (
    <div
      role="status"
      aria-live="polite"
      className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
    >
      <Loader2 className="size-4 animate-spin" />
      Chargement…
    </div>
  );
}
