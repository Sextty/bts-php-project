/** Human-readable names for application statuses. */
export const STATUS_LABELS: Record<string, string> = {
  DRAFT: 'Brouillon',
  CANCELLED: 'Annulée',
  STEP_1_COMPLETED: 'Étape 1 terminée',
  STEP_2_COMPLETED: 'Étape 2 terminée',
  STEP_3_COMPLETED: 'Étape 3 terminée',
  READY_FOR_VALIDATION_1: 'Prête pour validation',
  VALIDATION_1_COMPLETED: 'Validation 1 réussie',
  VALIDATION_2: 'Validation 2',
  FINAL_LOCKED: 'Finalisée',
  SUBMITTED: 'Soumise',
  STAFF_APPROVED: 'Approuvée par le personnel',
  STAFF_REJECTED: 'Rejetée',
  APPROVED: 'Approuvée',
  REJECTED: 'Rejetée',
  APPOINTMENT_PROPOSED: 'Rendez-vous proposé',
  APPOINTMENT_CONFIRMED: 'Rendez-vous confirmé',
  APPOINTMENT_LOCKED: 'Contact requis',
};

/** Friendly description shown below the status label. */
export const STATUS_DESCRIPTIONS: Record<string, string> = {
  DRAFT: 'Complétez les étapes pour soumettre votre demande.',
  CANCELLED: 'Votre demande a été annulée.',
  STEP_1_COMPLETED: 'Informations personnelles enregistrées.',
  STEP_2_COMPLETED: 'Demande de crédit enregistrée.',
  STEP_3_COMPLETED: 'Informations du projet enregistrées.',
  READY_FOR_VALIDATION_1: 'Vous pouvez lancer la validation des documents.',
  VALIDATION_1_COMPLETED: 'Documents vérifiés. Confirmez pour finaliser.',
  VALIDATION_2: 'Finalisation en cours…',
  FINAL_LOCKED: 'Dossier finalisé. Soumission en cours…',
  SUBMITTED: 'Votre demande est en attente de révision par BTS Bank.',
  STAFF_APPROVED: 'Votre demande a été approuvée par le personnel.',
  STAFF_REJECTED: 'Votre demande a été rejetée.',
  APPROVED: 'Votre demande a été approuvée.',
  REJECTED: 'Votre demande a été rejetée.',
  APPOINTMENT_PROPOSED: 'Un rendez-vous vous a été proposé. Veuillez confirmer ou refuser.',
  APPOINTMENT_CONFIRMED: 'Votre rendez-vous est confirmé. Présentez-vous à l\'agence.',
  APPOINTMENT_LOCKED: 'Un membre du personnel vous contactera pour organiser le rendez-vous.',
};

export function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status;
}

export function statusDescription(status: string): string {
  return STATUS_DESCRIPTIONS[status] ?? '';
}

/** Which phase a status belongs to — used by the stepper and timeline. */
export type StatusPhase = 'draft' | 'validation' | 'submitted' | 'review' | 'appointment' | 'terminal';

const PHASE_MAP: Record<string, StatusPhase> = {
  DRAFT: 'draft',
  STEP_1_COMPLETED: 'draft',
  STEP_2_COMPLETED: 'draft',
  STEP_3_COMPLETED: 'draft',
  READY_FOR_VALIDATION_1: 'validation',
  VALIDATION_1_COMPLETED: 'validation',
  VALIDATION_2: 'validation',
  FINAL_LOCKED: 'validation',
  SUBMITTED: 'submitted',
  STAFF_APPROVED: 'review',
  STAFF_REJECTED: 'terminal',
  APPROVED: 'review',
  REJECTED: 'terminal',
  APPOINTMENT_PROPOSED: 'appointment',
  APPOINTMENT_CONFIRMED: 'appointment',
  APPOINTMENT_LOCKED: 'appointment',
  CANCELLED: 'terminal',
};

export function statusPhase(status: string): StatusPhase {
  return PHASE_MAP[status] ?? 'draft';
}
