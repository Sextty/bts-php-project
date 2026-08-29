import { clearToken, getToken } from '@/lib/auth/token';

export function getApiBaseUrl(): string {
  return (process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000').replace(/\/$/, '');
}

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

function getClientTelemetryHeaders(): Record<string, string> {
  if (typeof window === 'undefined') return {};
  const headers: Record<string, string> = {};
  try {
    if (navigator.platform) headers['X-Client-Platform'] = navigator.platform;
    if (navigator.hardwareConcurrency) headers['X-Client-CPU-Cores'] = String(navigator.hardwareConcurrency);
    const navigatorWithMemory = navigator as Navigator & { deviceMemory?: number };
    if (navigatorWithMemory.deviceMemory) headers['X-Client-RAM'] = `${navigatorWithMemory.deviceMemory} Go`;
    if (window.screen) headers['X-Client-Screen'] = `${window.screen.width}x${window.screen.height}`;
    let deviceId = localStorage.getItem('bts_device_fingerprint');
    if (!deviceId) {
      deviceId = 'DEV-' + Math.random().toString(36).substring(2, 10).toUpperCase();
      localStorage.setItem('bts_device_fingerprint', deviceId);
    }
    headers['X-Device-Fingerprint'] = deviceId;
  } catch {}
  return headers;
}

export async function apiFetch<T>(
  path: string,
  options: { method?: 'GET' | 'POST' | 'PUT' | 'DELETE'; body?: unknown; auth?: boolean } = {}
): Promise<T> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...getClientTelemetryHeaders(),
  };

  if (options.auth) {
    const token = getToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;
  }

  const response = await fetch(`${getApiBaseUrl()}/api${path}`, {
    method: options.method ?? 'GET',
    headers,
    cache: 'no-store',
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
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...getClientTelemetryHeaders(),
  };

  const token = getToken();
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const response = await fetch(`${getApiBaseUrl()}/api${path}`, {
    method: 'POST',
    headers,
    cache: 'no-store',
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
    // credential and send the customer back to the login screen.
    if (response.status === 401 && typeof window !== 'undefined') {
      clearToken();
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
