import { Alert, AlertDescription } from '@/components/ui/alert';

export function ErrorAlert({ message }: { message: string | null }) {
  if (!message) return null;

  return (
    <Alert variant="destructive" className="mb-4">
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  );
}
