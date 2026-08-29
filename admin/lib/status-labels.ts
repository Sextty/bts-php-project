/** Libellés lisibles des statuts de dossier — utilisés dans toutes les vues admin. */
export const STATUS_LABELS: Record<string, string> = {
  DRAFT: 'Brouillon',
  CANCELLED: 'Annulé',
  STEP_1_COMPLETED: 'Étape 1 complétée',
  STEP_2_COMPLETED: 'Étape 2 complétée',
  STEP_3_COMPLETED: 'Étape 3 complétée',
  READY_FOR_VALIDATION_1: 'Prêt pour validation',
  VALIDATION_1_COMPLETED: 'Validation 1 réussie',
  VALIDATION_2: 'Validation 2',
  FINAL_LOCKED: 'Verrouillé',
  SUBMITTED: 'Soumis',
  STAFF_APPROVED: 'Approuvé (conseiller)',
  STAFF_REJECTED: 'Rejeté',
  APPROVED: 'Approuvé',
  REJECTED: 'Rejeté',
  APPOINTMENT_PROPOSED: 'RDV proposé',
  APPOINTMENT_CONFIRMED: 'RDV confirmé',
  APPOINTMENT_LOCKED: 'Contact requis',
};

export function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status;
}

/** Couleur TailwindCSS pour un badge de statut. */
export function statusColor(status: string): string {
  switch (status) {
    case 'DRAFT':
    case 'STEP_1_COMPLETED':
    case 'STEP_2_COMPLETED':
    case 'STEP_3_COMPLETED':
      return 'bg-slate-100 text-slate-700 border-slate-200';
    case 'READY_FOR_VALIDATION_1':
    case 'VALIDATION_1_COMPLETED':
    case 'VALIDATION_2':
    case 'FINAL_LOCKED':
      return 'bg-blue-50 text-blue-700 border-blue-200';
    case 'SUBMITTED':
    case 'STAFF_APPROVED':
      return 'bg-amber-50 text-amber-700 border-amber-200';
    case 'APPROVED':
    case 'APPOINTMENT_PROPOSED':
    case 'APPOINTMENT_CONFIRMED':
      return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    case 'REJECTED':
    case 'STAFF_REJECTED':
    case 'CANCELLED':
      return 'bg-red-50 text-red-700 border-red-200';
    case 'APPOINTMENT_LOCKED':
      return 'bg-purple-50 text-purple-700 border-purple-200';
    default:
      return 'bg-gray-100 text-gray-700 border-gray-200';
  }
}
