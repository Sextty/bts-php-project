import { apiFetch } from '@/lib/api/client';

export function downloadStaffDocument(applicationId: number, documentId: number): string {
  const base = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';
  return `${base}/api/staff/applications/${applicationId}/documents/${documentId}`;
}
