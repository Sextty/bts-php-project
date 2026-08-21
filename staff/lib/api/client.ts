import { clearStaffToken, getStaffToken } from '@/lib/auth/staff-token';

export function getApiBaseUrl(): string {
  if (typeof window !== 'undefined') {
    const configured = process.env.NEXT_PUBLIC_API_URL;
    if (configured && !configured.includes('localhost') && !configured.includes('127.0.0.1')) {
      return configured;
    }
    return `${window.location.protocol}//${window.location.hostname}:8000`;
  }
  return process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000';
}

const API_URL = getApiBaseUrl();

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

/**
 * Staff-portal fetch: always uses the staff token (bts_staff_access_token) — this app
 * has no customer surface, so there is no dual-token branching here. A 401 means the
 * staff session died: clear it and return to the staff login.
 */
export async function apiFetch<T>(
  path: string,
  options: { method?: 'GET' | 'POST' | 'PUT' | 'DELETE'; body?: unknown; auth?: boolean | 'staff' } = {}
): Promise<T> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json', Accept: 'application/json' };

  if (options.auth) {
    const token = getStaffToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;
  }

  const response = await fetch(`${API_URL}/api${path}`, {
    method: options.method ?? 'GET',
    headers,
    ...(options.body !== undefined ? { body: JSON.stringify(options.body) } : {}),
  });

  return handleApiResponse<T>(response);
}

export async function apiUpload<T>(path: string, formData: FormData): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };

  const token = getStaffToken();
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
  let json: Record<string, unknown> = {};

  if (text) {
    try {
      json = JSON.parse(text) as Record<string, unknown>;
    } catch {
      if (!response.ok) {
        throw new ApiError(
          `HTTP_${response.status}`,
          `Erreur serveur (HTTP ${response.status}: ${response.statusText || 'Erreur'}).`,
          response.status
        );
      }
      throw new ApiError('INVALID_JSON', 'Format de réponse JSON invalide.', response.status);
    }
  }

  if (!response.ok) {
    // An expired/revoked token is a dead session, not an error to display: drop the
    // credential and send the staff member back to their login screen.
    if (response.status === 401 && typeof window !== 'undefined') {
      clearStaffToken();
      if (!window.location.pathname.startsWith('/login')) {
        // Plain module, not a component: no useRouter() available. A full reload is
        // intentional here — we want a clean app state after the session died.
        // eslint-disable-next-line @next/next/no-location-assign-relative-destination
        window.location.assign(`${window.location.origin}/login`);
      }
    }

    const error: ApiErrorBody = (json.error as ApiErrorBody) ?? {
      code: `HTTP_${response.status}`,
      message: (json.message as string) ?? 'Une erreur est survenue.',
    };
    throw new ApiError(error.code, error.message, response.status, error.fields, error.errors);
  }

  return json.data as T;
}