import { apiDownload } from '@/lib/api/client';

export function downloadStaffDocument(applicationId: number, documentId: number): Promise<Blob> {
  return apiDownload(`/staff/applications/${applicationId}/documents/${documentId}`);
}
