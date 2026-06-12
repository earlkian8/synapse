import type { AssistantResponse } from './types';

/** Read Laravel's XSRF cookie so a plain fetch passes CSRF verification. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Send a turn to the server-side employee agent. Uses multipart so an optional
 * CV/file can ride along for multimodal extraction.
 */
export async function sendToAssistant(opts: {
    message: string;
    history: { role: string; text: string }[];
    file: File | null;
    signal?: AbortSignal;
}): Promise<AssistantResponse> {
    const body = new FormData();
    body.append('message', opts.message);
    body.append('history', JSON.stringify(opts.history));

    if (opts.file) {
        body.append('file', opts.file);
    }

    const response = await fetch('/employees/assistant', {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': xsrfToken(), Accept: 'application/json' },
        credentials: 'same-origin',
        body,
        signal: opts.signal,
    });

    const data = (await response
        .json()
        .catch(() => null)) as AssistantResponse | null;

    if (!data) {
        throw new Error(
            response.status === 419
                ? 'Your session expired. Please refresh the page and try again.'
                : `The assistant request failed (${response.status}).`,
        );
    }

    return data;
}
