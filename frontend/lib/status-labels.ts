/** Human-readable names for application statuses, shared by the customer list and staff screens. */
export const STATUS_LABELS: Record<string, string> = {
  DRAFT: 'Draft',
  STEP_1_COMPLETED: 'Client saved',
  STEP_2_COMPLETED: 'Credit request saved',
  STEP_3_COMPLETED: 'Project saved',
  READY_FOR_VALIDATION_1: 'Ready for validation',
  VALIDATION_1_COMPLETED: 'Validation 1 passed',
  VALIDATION_2: 'Validation 2',
  FINAL_LOCKED: 'Finalized',
  SUBMITTED: 'Submitted',
  STAFF_APPROVED: 'Approved by staff',
  STAFF_REJECTED: 'Rejected',
  APPROVED: 'Approved',
  REJECTED: 'Rejected',
  APPOINTMENT_PROPOSED: 'Appointment proposed',
  APPOINTMENT_CONFIRMED: 'Appointment confirmed',
  APPOINTMENT_LOCKED: 'Contact required',
};

export function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status;
}
