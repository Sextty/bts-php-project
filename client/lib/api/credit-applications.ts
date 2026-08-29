import { apiFetch, apiUpload } from '@/lib/api/client';
import { getToken } from '@/lib/auth/token';
import type { AppointmentDto } from '@/lib/api/appointments';

const API_URL = (process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000').replace(/\/$/, '');

export type ApplicationStatus =
  | 'DRAFT'
  | 'CANCELLED'
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
  type_pid?: string | null;
  numero_pid?: string | null;
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

export interface BranchDto {
  id: number;
  name: string;
  ville: string;
  governorate: string;
  address: string;
  google_maps_url: string;
}

export interface CreditApplicationDto {
  id: number;
  status: ApplicationStatus;
  is_locked: boolean;
  can_be_deleted: boolean;
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
}

export function listApplications(page = 1) {
  return apiFetch<{
    applications: CreditApplicationDto[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
  }>(`/applications?page=${page}&per_page=25`, { auth: true });
}

export function createApplication() {
  return apiFetch<{ application: CreditApplicationDto }>('/applications', { method: 'POST', auth: true });
}

export function getApplication(id: number) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}`, { auth: true });
}

export function deleteApplication(id: number) {
  return apiFetch<null>(`/applications/${id}`, { method: 'DELETE', auth: true });
}

export function updateClient(id: number, input: Omit<ClientDto, 'code_client'>) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}/client`, {
    method: 'PUT',
    body: input,
    auth: true,
  });
}

export function updateCreditRequest(id: number, input: Omit<CreditRequestDto, 'n_demande' | 'identifiant_personne' | 'type_pid' | 'numero_pid'>) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}/credit`, {
    method: 'PUT',
    body: input,
    auth: true,
  });
}

export function updateProject(id: number, input: Omit<ProjectDto, 'code_projet' | 'identifiant_personne'>) {
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

export function runValidationOne(id: number, forceAiValidation = false) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}/validation-1`, {
    method: 'POST',
    body: { force_ai_validation: forceAiValidation },
    auth: true,
  });
}

export function confirmValidationTwo(id: number) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}/validation-2`, {
    method: 'POST',
    auth: true,
  });
}

export function cancelApplication(id: number) {
  return apiFetch<{ application: CreditApplicationDto }>(`/applications/${id}/cancel`, {
    method: 'POST',
    auth: true,
  });
}

export async function downloadDocumentBlob(
  applicationId: number,
  documentId: number
): Promise<{ blob: Blob; mimeType: string }> {
  const token = getToken();
  const response = await fetch(`${API_URL}/api/applications/${applicationId}/documents/${documentId}`, {
    headers: {
      Accept: '*/*',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (!response.ok) {
    throw new Error('Failed to load document preview.');
  }

  const mimeType = response.headers.get('content-type') || 'application/octet-stream';
  const blob = await response.blob();
  return { blob, mimeType };
}
