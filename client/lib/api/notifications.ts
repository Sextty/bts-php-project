import { apiFetch } from '@/lib/api/client';

export interface NotificationDto {
  id: number;
  type: string;
  title: string;
  body: string;
  data: Record<string, unknown> | null;
  read_at: string | null;
  created_at: string;
}

export async function getNotifications(limit = 50): Promise<NotificationDto[]> {
  return apiFetch<NotificationDto[]>(`/notifications?limit=${limit}`, { auth: true });
}

export async function markNotificationsRead(id?: number): Promise<{ message: string }> {
  const params = id ? `?id=${id}` : '';
  return apiFetch<{ message: string }>(`/notifications/mark-read${params}`, { method: 'POST', auth: true });
}
