/**
 * Customer-application types only. The admin portal never calls these endpoints - the
 * customer surface (applications CRUD, document upload, validation, submit) lives in
 * the client app. These types are shared for rendering (e.g. ApplicationStepper).
 */
export type ApplicationStatus =
  | 'DRAFT'
  | 'STEP_1_COMPLETED'
  | 'STEP_2_COMPLETED'
  | 'STEP_3_COMPLETED'
  | 'READY_FOR_VALIDATION_1'
  | 'VALIDATION_1_COMPLETED'
  | 'VALIDATION_2'
  | 'FINAL_LOCKED'
  | 'SUBMITTED'
  | 'STAFF_APPROVED'
  | 'STAFF_REJECTED'
  | 'APPROVED'
  | 'REJECTED'
  | 'APPOINTMENT_PROPOSED'
  | 'APPOINTMENT_CONFIRMED'
  | 'APPOINTMENT_LOCKED'
  | 'CANCELLED';

export interface ClientDto {
  code_client: string;
  civilite: string;
  nom: string;
  prenom: string;
  nom_epoux: string | null;
  deuxieme_prenom: string | null;
  date_naissance: string;
  lieu_naissance: string;
  pays_naissance: string;
  nationalite: string;
  pays_residence: string;
  etat_civil: string;
  nombre_enfants: number;
  type_pid: string;
  numero_pid: string;
  date_delivrance_pid: string;
  lieu_delivrance_pid: string;
  numero_carte_sejour: string | null;
  profession: string;
  date_entree_relation: string;
}

export interface CreditRequestDto {
  n_demande: string;
  identifiant_personne: string;
  nom_ou_rs: string;
  prenom_ou_dc: string;
  type_pid: string;
  numero_pid: string;
  origine: string;
  date_depot: string;
  date_reception: string;
  type_demande: string;
  code_devise: string;
  montant_global_sollicite: string;
  montant_eqp?: string | number | null;
  montant_fdr?: string | number | null;
  montant_amg?: string | number | null;
  montant_chp?: string | number | null;
  nombre_credits_sollicites: number;
  unite_depot: string;
}

export interface ProjectDto {
  code_projet: string;
  identifiant_personne: string;
  nom_ou_rs: string;
  prenom_ou_dc: string;
  type_projet: string;
  objet: string;
  adresse: string;
  ville: string;
  code_postal: string;
  activite: string;
  description: string;
  delegation: string;
  localisation: string;
  latitude?: number | string | null;
  longitude?: number | string | null;
  cout: string;
  investissement_personnel: string;
  financement: string;
  revenus: string;
  depenses: string;
}

export interface AiMismatchDto {
  field: string;
  expected: string | null;
  extracted: string | null;
  severity: 'critical' | 'warning';
}

export interface DocumentDto {
  id: number;
  document_type: string;
  original_filename: string;
  mime_type: string;
  size_bytes: number;
  uploaded_at: string;
  ai_verified_at: string | null;
  ai_is_valid: boolean | null;
  ai_confidence: 'high' | 'medium' | 'low' | null;
  ai_comment: string | null;
  ai_extracted_fields: Record<string, string | null> | null;
  ai_mismatches: AiMismatchDto[] | null;
}

export interface ValidationStepDto {
  step: 'validation_1' | 'validation_2';
  status: 'passed' | 'failed';
  errors: string[] | null;
  created_at: string;
}

export interface BranchDto {
  id?: number;
  name: string;
  address: string;
  ville: string;
  governorate?: string;
  google_maps_url?: string;
}

export interface AppointmentDto {
  id: number;
  attempt_number: number;
  max_attempts: number;
  scheduled_date: string;
  scheduled_time: string;
  status: 'proposed' | 'accepted' | 'rejected' | 'cancelled';
  is_auto_scheduled_future: boolean;
  decided_at: string | null;
  branch?: BranchDto;
}

export interface CreditApplicationDto {
  id: number;
  status: ApplicationStatus;
  is_locked: boolean;
  submitted_at: string | null;
  rejection_reason: string | null;
  created_at: string;
  client?: ClientDto;
  credit_request?: CreditRequestDto;
  project?: ProjectDto;
  documents?: DocumentDto[];
  validation_steps?: ValidationStepDto[];
  branch?: BranchDto;
  latest_appointment?: AppointmentDto | null;
  appointments?: AppointmentDto[];
}