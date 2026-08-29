import { apiFetch, apiUpload } from '@/lib/api/client';

export interface ReportMessageDto {
  id: number;
  sender_type: 'customer' | 'staff';
  sender_name: string;
  body: string;
  has_attachment?: boolean;
  attachment_url?: string | null;
  attachment_name?: string | null;
  attachment_type?: string | null;
  attachment_size?: number | null;
  created_at: string;
}

export interface ReportThreadDto {
  messages: ReportMessageDto[];
  meta?: { has_more: boolean; next_before_id: number | null; limit: number };
  is_closed?: boolean;
  closed_at?: string | null;
  closed_reason?: string | null;
}

export function getReportMessages(applicationId: number, beforeId?: number) {
  const query = beforeId ? `?before_id=${beforeId}` : '';
  return apiFetch<ReportThreadDto>(`/applications/${applicationId}/report/messages${query}`, { auth: true });
}

export function sendReportMessage(applicationId: number, body: string) {
  return apiFetch<{ message: ReportMessageDto }>(`/applications/${applicationId}/report/messages`, {
    method: 'POST',
    body: { body },
    auth: true,
  });
}

export function sendReportMessageWithAttachment(applicationId: number, body?: string, file?: File | null) {
  if (file) {
    const formData = new FormData();
    if (body !== undefined && body !== null) formData.append('body', body);
    formData.append('file', file);
    return apiUpload<{ message: ReportMessageDto }>(`/applications/${applicationId}/report/messages`, formData);
  }
  return sendReportMessage(applicationId, body || '');
}
