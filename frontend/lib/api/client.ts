import { getToken } from '@/lib/auth/token';
import { getStaffToken } from '@/lib/auth/staff-token';

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';

export interface ApiErrorBody {
  code: string;
  message: string;
  /** Per-field validation messages (VALIDATION_ERROR shape: field name -> messages). */
  fields?: Record<string, string[]>;
  /** Free-form cross-section errors (e.g. VALIDATION_1_FAILED: a flat list of business-rule failures). */
  errors?: string[];
}

/** Thrown for every non-2xx response — callers switch on `.code`, never on the message text. */
export class ApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly status: number,
    public readonly fields?: Record<string, string[]>,
    public readonly errors?: string[]
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export async function apiFetch<T>(
  path: string,
  options: { method?: 'GET' | 'POST' | 'PUT' | 'DELETE'; body?: unknown; auth?: boolean | 'staff' } = {}
): Promise<T> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json', Accept: 'application/json' };

  if (options.auth) {
    // 'staff' reads a separate localStorage key (lib/auth/staff-token.ts) — a staff session and
    // a customer session can coexist in the same browser without either overwriting the other.
    const token = options.auth === 'staff' ? getStaffToken() : getToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;
  }

  const response = await fetch(`${API_URL}/api${path}`, {
    method: options.method ?? 'GET',
    headers,
    ...(options.body !== undefined ? { body: JSON.stringify(options.body) } : {}),
  });

  return handleApiResponse<T>(response);
}

/**
 * File uploads can't go through apiFetch — a File in a FormData body must not be
 * JSON.stringify'd, and the browser needs to set its own multipart Content-Type (with
 * boundary), so we deliberately don't set one here.
 */
export async function apiUpload<T>(path: string, formData: FormData): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };

  const token = getToken();
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const response = await fetch(`${API_URL}/api${path}`, {
    method: 'POST',
    headers,
    body: formData,
  });

  return handleApiResponse<T>(response);
}

async function handleApiResponse<T>(response: Response): Promise<T> {
  const text = await response.text();
  const json = text ? JSON.parse(text) : {};

  if (!response.ok) {
    const error: ApiErrorBody = json.error ?? { code: 'UNKNOWN_ERROR', message: 'Something went wrong.' };
    throw new ApiError(error.code, error.message, response.status, error.fields, error.errors);
  }

  return json.data as T;
}
