import { Alert, AlertDescription } from '@/components/ui/alert';

/**
 * Displays a server error, plus a per-field list when the API returned a VALIDATION_ERROR
 * (`fields` shape: field name → messages) — without it, Laravel's summary ("The x field is
 * required. (and 1 more error)") leaves the user guessing which fields to fix.
 */
export function ErrorAlert({
  message,
  fields,
}: {
  message: string | null;
  fields?: Record<string, string[]> | null;
}) {
  const fieldMessages = fields ? Object.values(fields).flat() : [];

  if (!message && fieldMessages.length === 0) return null;

  return (
    <Alert variant="destructive" className="mb-5 border-red-200 bg-red-50 text-red-950" role="alert" aria-live="assertive">
      <AlertDescription>
        {message && <p>{message}</p>}
        {fieldMessages.length > 0 && (
          <ul className="mt-1.5 list-disc space-y-0.5 pl-5">
            {fieldMessages.map((m) => (
              <li key={m}>{m}</li>
            ))}
          </ul>
        )}
      </AlertDescription>
    </Alert>
  );
}
