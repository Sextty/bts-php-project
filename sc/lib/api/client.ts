import { clearSecurityToken, getSecurityToken } from '@/lib/auth/security-token';

export function getApiBaseUrl(): string {
  return (process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000').replace(/\/$/, '');
}

export interface ApiErrorBody {
  code: string;
  message: string;
  fields?: Record<string, string[]>;
  errors?: string[];
}

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
  options: { method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'; body?: unknown; auth?: boolean; signal?: AbortSignal } = {}
): Promise<T> {
  const requiresAuth = options.auth !== false;
  const token = requiresAuth ? getSecurityToken() : null;

  if (requiresAuth && !token && typeof window !== 'undefined') {
    if (!window.location.pathname.startsWith('/login')) {
      window.location.replace(`${window.location.origin}/login`);
    }
    throw new ApiError('UNAUTHENTICATED', 'Session de sécurité absente.', 401);
  }

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const baseUrl = getApiBaseUrl();

  let response: Response;
  try {
    response = await fetch(`${baseUrl}/api${path}`, {
      method: options.method ?? 'GET',
      headers,
      cache: 'no-store',
      ...(options.body !== undefined ? { body: JSON.stringify(options.body) } : {}),
      signal: options.signal,
    });
  } catch (networkError: unknown) {
    if (networkError instanceof DOMException && networkError.name === 'AbortError') throw networkError;
    throw new ApiError(
      'NETWORK_ERROR',
      `Impossible de contacter l'API BTS Bank à l'adresse ${baseUrl}. Vérifiez que le serveur backend est démarré sur le port 8000.`,
      0
    );
  }

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
    if (response.status === 401 && typeof window !== 'undefined') {
      clearSecurityToken();
      if (!window.location.pathname.startsWith('/login')) {
        window.location.replace(`${window.location.origin}/login`);
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

export function getErrorMessage(error: unknown, fallback = 'Service indisponible.'): string {
  return error instanceof Error && error.message ? error.message : fallback;
}
