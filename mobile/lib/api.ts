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

/**
 * How long a request may take before it is given up. React Native's Android HTTP
 * client has no timeouts of its own, so without one a request on a connection
 * that died never settles — and whatever waits on it (the punch queue) waits for
 * ever.
 */
const DEFAULT_TIMEOUT_MS = 30_000;

export type RequestOptions = { timeoutMs?: number };

async function request<T>(method: string, path: string, body?: unknown, options: RequestOptions = {}): Promise<T> {
  const token = getToken();
  const isForm = body instanceof FormData;

  const headers: Record<string, string> = { Accept: 'application/json' };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  if (body !== undefined && !isForm) {
    headers['Content-Type'] = 'application/json';
  }

  // The deadline covers the whole exchange, the body as well as the headers.
  const controller = new AbortController();
  const deadline = setTimeout(() => controller.abort(), options.timeoutMs ?? DEFAULT_TIMEOUT_MS);

  try {
    const response = await fetch(`${API_URL}${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : isForm ? (body as FormData) : JSON.stringify(body),
      signal: controller.signal,
    });

    if (!response.ok) {
      throw await parseError(response);
    }

    if (response.status === 204) {
      return undefined as T;
    }

    return (await response.json()) as T;
  } catch (error) {
    // Status 0: no answer came back — to the caller, the same as no connection.
    if (controller.signal.aborted && !(error instanceof ApiError)) {
      throw new ApiError(0, 'The server took too long to answer.');
    }

    throw error;
  } finally {
    clearTimeout(deadline);
  }
}

export const api = {
  get: <T>(path: string, options?: RequestOptions) => request<T>('GET', path, undefined, options),
  post: <T>(path: string, body?: unknown, options?: RequestOptions) => request<T>('POST', path, body, options),
  patch: <T>(path: string, body?: unknown, options?: RequestOptions) => request<T>('PATCH', path, body, options),
  delete: <T>(path: string, options?: RequestOptions) => request<T>('DELETE', path, undefined, options),
};
