import { apiFetch } from '@/lib/api/client';

export interface UserDto {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  phone_verified: boolean;
  auth_provider: 'password' | 'google';
}

export interface PreAuthResponse {
  pre_auth_token: string;
  /** Present when the OTP channel can't reach this account yet (Telegram handshake pending). */
  requires_telegram_link?: true;
  telegram_link_url?: string;
}

export interface SessionResponse {
  access_token: string;
  user: UserDto;
}

export function register(input: {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  password: string;
  password_confirmation: string;
}) {
  return apiFetch<{ user_id: number } & PreAuthResponse>('/auth/register', {
    method: 'POST',
    body: input,
  });
}

/**
 * Polled while the user is on the Telegram-link screen. Returns linked:true once they've pressed
 * Start in the bot — at which point the backend has already dispatched their OTP.
 */
export function checkTelegramLink(input: { pre_auth_token: string; purpose: 'registration' | 'login' }) {
  return apiFetch<{ linked: boolean }>('/auth/telegram/link-status', { method: 'POST', body: input });
}

export function verifyRegistrationOtp(input: { pre_auth_token: string; otp_code: string }) {
  return apiFetch<SessionResponse>('/auth/verify-registration-otp', { method: 'POST', body: input });
}

export function login(input: { identifier: string; password: string }) {
  return apiFetch<PreAuthResponse>('/auth/login', { method: 'POST', body: input });
}

export function verifyLoginOtp(input: { pre_auth_token: string; otp_code: string }) {
  return apiFetch<SessionResponse>('/auth/login/verify-otp', { method: 'POST', body: input });
}

export type GoogleAuthResponse =
  | { access_token: string; user: UserDto }
  | { requires_phone: true; pre_auth_token: string }
  | { requires_otp: true; pre_auth_token: string };

export function googleAuth(input: { google_id_token: string }) {
  return apiFetch<GoogleAuthResponse>('/auth/google', { method: 'POST', body: input });
}

export function googleSetPhone(input: { pre_auth_token: string; phone: string }) {
  return apiFetch<{ requires_otp: true; pre_auth_token: string }>('/auth/google/set-phone', {
    method: 'POST',
    body: input,
  });
}

export function googleVerifyOtp(input: { pre_auth_token: string; otp_code: string }) {
  return apiFetch<SessionResponse>('/auth/google/verify-otp', { method: 'POST', body: input });
}

export function logout() {
  return apiFetch<null>('/auth/logout', { method: 'POST', auth: true });
}

export function forgotPassword(input: { email: string }) {
  return apiFetch<{ message: string }>('/auth/password/forgot', { method: 'POST', body: input });
}

export function resetPassword(input: {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
}) {
  return apiFetch<{ message: string }>('/auth/password/reset', { method: 'POST', body: input });
}

export function getCurrentUser() {
  return apiFetch<{ user: UserDto }>('/user', { auth: true });
}
