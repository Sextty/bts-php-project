import { apiFetch } from '@/lib/api/client';

export interface NotificationDto {
  id: string;
  type: string;
  title: string;
  body: string;
  data: Record<string, unknown>;
  read_at: string | null;
  created_at: string;
}

export function getNotifications() {
  return apiFetch<NotificationDto[] | { notifications: NotificationDto[] }>('/staff/notifications', { auth: 'staff' });
}

export function markNotificationsRead(id?: number | string) {
  const query = id !== undefined ? `?id=${encodeURIComponent(id)}` : '';
  return apiFetch<{ message: string }>(`/staff/notifications/mark-read${query}`, {
    method: 'POST',
    auth: 'staff',
  });
}
