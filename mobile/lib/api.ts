/**
 * The single HTTP client for the SYNAPSE API. The base URL comes from
 * `EXPO_PUBLIC_API_URL` in `mobile/.env` (see `.env.example`) — the one place to
 * change it — falling back to `app.json` `extra.apiUrl` and then localhost. It
 * injects the Bearer token and turns Laravel responses into typed data or a
 * structured {@link ApiError} (so the UI can surface 422 validation messages).
 */
import Constants from 'expo-constants';

const DEFAULT_URL = 'http://localhost:8000/api';

export const API_URL: string =
  process.env.EXPO_PUBLIC_API_URL ??
  (Constants.expoConfig?.extra as { apiUrl?: string } | undefined)?.apiUrl ??
  DEFAULT_URL;

export class ApiError extends Error {
  status: number;
  /** Laravel `errors` bag: field → first message. */
  fieldErrors: Record<string, string>;

  constructor(status: number, message: string, fieldErrors: Record<string, string> = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.fieldErrors = fieldErrors;
  }
}

type TokenProvider = () => string | null;

let getToken: TokenProvider = () => null;

/** Wire the auth layer's token getter so every request carries the Bearer token. */
export function setTokenProvider(provider: TokenProvider) {
  getToken = provider;
}

async function parseError(response: Response): Promise<ApiError> {
  let message = `Request failed (${response.status})`;
  const fieldErrors: Record<string, string> = {};

  try {
    const body = await response.json();

    if (typeof body?.message === 'string') {
      message = body.message;
    }

    if (body?.errors && typeof body.errors === 'object') {
      for (const [field, messages] of Object.entries(body.errors as Record<string, string[]>)) {
        if (Array.isArray(messages) && messages[0]) {
          fieldErrors[field] = messages[0];
        }
      }
    }
  } catch {
    // Non-JSON error body (e.g. an HTML 500) — keep the generic message.
  }

  return new ApiError(response.status, message, fieldErrors);
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const token = getToken();
  const isForm = body instanceof FormData;

  const headers: Record<string, string> = { Accept: 'application/json' };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  if (body !== undefined && !isForm) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(`${API_URL}${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : isForm ? (body as FormData) : JSON.stringify(body),
  });

  if (!response.ok) {
    throw await parseError(response);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return (await response.json()) as T;
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
  patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body),
  delete: <T>(path: string) => request<T>('DELETE', path),
};
