import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const REJECTED_STATUSES = new Set(['REJECTED', 'STAFF_REJECTED']);
const APPROVED_STATUSES = new Set(['APPROVED', 'STAFF_APPROVED', 'SUBMITTED']);
const ATTENTION_STATUSES = new Set(['APPOINTMENT_LOCKED']);

/**
 * Applies semantic color to an application status badge (green = approved/submitted, red =
 * rejected, amber = needs the customer's attention) so the list is scannable at a glance instead
 * of every status looking the same neutral gray.
 */
export function StatusBadge({ status, label }: { status: string; label: string }) {
  if (REJECTED_STATUSES.has(status)) {
    return <Badge variant="destructive">{label}</Badge>;
  }
  if (APPROVED_STATUSES.has(status)) {
    return (
      <Badge className={cn('border-transparent bg-emerald-600/10 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400')}>
        {label}
      </Badge>
    );
  }
  if (ATTENTION_STATUSES.has(status)) {
    return (
      <Badge className={cn('border-transparent bg-amber-500/10 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400')}>
        {label}
      </Badge>
    );
  }
  return <Badge variant="outline">{label}</Badge>;
}
