import { apiFetch, apiUpload } from '@/lib/api/client';

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
  | 'APPOINTMENT_LOCKED';

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
  cout: string;
  investissement_personnel: string;
  financement: string;
  revenus: string;
  depenses: string;
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
}

export interface ValidationStepDto {
  step: 'validation_1' | 'validation_2';
  status: 'passed' | 'failed';
  errors: string[] | null;
  created_at: string;
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
}

export function listApplications() {
  return apiFetch<{ applications: CreditApplicationDto[] }>('/applications', { auth: true });
}

export function createApplication() {
  return apiFetch<{ application: CreditApplicationDto }>('/applications', { method: 'POST', auth: true });
}

export function getApplication(id: number) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}`, { auth: true });
}

export function updateClient(id: number, input: Omit<ClientDto, 'code_client'>) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}/client`, {
    method: 'PUT',
    body: input,
    auth: true,
  });
}

export function updateCreditRequest(id: number, input: Omit<CreditRequestDto, 'n_demande'>) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}/credit`, {
    method: 'PUT',
    body: input,
    auth: true,
  });
}

export function updateProject(id: number, input: ProjectDto) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}/project`, {
    method: 'PUT',
    body: input,
    auth: true,
  });
}

export function uploadDocument(id: number, documentType: string, file: File) {
  const formData = new FormData();
  formData.append('document_type', documentType);
  formData.append('file', file);

  return apiUpload<{ document: DocumentDto }>(`/applications/${id}/documents`, formData);
}

export function deleteDocument(id: number, documentId: number) {
  return apiFetch<null>(`/applications/${id}/documents/${documentId}`, { method: 'DELETE', auth: true });
}

export function runValidationOne(id: number) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}/validation-1`, {
    method: 'POST',
    auth: true,
  });
}

export function confirmValidationTwo(id: number) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}/validation-2`, {
    method: 'POST',
    auth: true,
  });
}

export function submitApplication(id: number) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}/submit`, {
    method: 'POST',
    auth: true,
  });
}
