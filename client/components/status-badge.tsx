import { Badge } from '@/components/ui/badge';

const REJECTED_STATUSES = new Set(['REJECTED', 'STAFF_REJECTED']);
const APPROVED_STATUSES = new Set(['APPROVED', 'STAFF_APPROVED']);
const IN_PROGRESS_STATUSES = new Set([
  'DRAFT',
  'STEP_1_COMPLETED',
  'STEP_2_COMPLETED',
  'STEP_3_COMPLETED',
  'READY_FOR_VALIDATION_1',
  'VALIDATION_1_COMPLETED',
  'VALIDATION_2',
  'FINAL_LOCKED',
]);
const ATTENTION_STATUSES = new Set(['APPOINTMENT_LOCKED']);
const SUBMITTED_STATUSES = new Set(['SUBMITTED']);
const APPOINTMENT_STATUSES = new Set(['APPOINTMENT_PROPOSED', 'APPOINTMENT_CONFIRMED']);
const CANCELLED_STATUSES = new Set(['CANCELLED']);

/**
 * Applies semantic color to an application status badge so the list is scannable at a glance.
 */
export function StatusBadge({ status, label }: { status: string; label: string }) {
  if (REJECTED_STATUSES.has(status)) {
    return <Badge variant="destructive">{label}</Badge>;
  }
  if (CANCELLED_STATUSES.has(status)) {
    return <Badge variant="destructive">{label}</Badge>;
  }
  if (APPROVED_STATUSES.has(status)) {
    return <Badge variant="success">{label}</Badge>;
  }
  if (SUBMITTED_STATUSES.has(status)) {
    return <Badge variant="info">{label}</Badge>;
  }
  if (APPOINTMENT_STATUSES.has(status)) {
    return <Badge variant="success">{label}</Badge>;
  }
  if (IN_PROGRESS_STATUSES.has(status)) {
    return <Badge variant="info">{label}</Badge>;
  }
  if (ATTENTION_STATUSES.has(status)) {
    return <Badge variant="warning">{label}</Badge>;
  }
  return <Badge variant="outline">{label}</Badge>;
}
