import { problemReportRoutes } from './routes';
import type { ProblemReport, ReportCategory, ReportImpact } from './types';

/**
 * Sending a problem report (ADR 0096). A plain fetch, not an Inertia visit: the
 * dialog sits over whatever page the person was on, and that page — its
 * filters, its scroll, a half-filled form — must be left exactly as it was.
 */

export type ReportInput = {
    category: ReportCategory;
    impact: ReportImpact;
    subject: string;
    description: string;
    page: string;
    screenshots: File[];
};

export type SendOutcome =
    | { ok: true; report: ProblemReport }
    | { ok: false; message: string; errors: Record<string, string> };

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * A few facts about the screen the report was sent from, which a layout
 * problem is often down to. Nothing personal: the size of the window, the
 * time zone, the language and the theme.
 */
export function displayContext(): Record<string, string> {
    const dark = document.documentElement.classList.contains('dark');

    return {
        viewport: `${window.innerWidth}×${window.innerHeight}`,
        screen: `${window.screen.width}×${window.screen.height}`,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone ?? '',
        language: navigator.language ?? '',
        theme: dark ? 'dark' : 'light',
    };
}

export async function sendReport(input: ReportInput): Promise<SendOutcome> {
    const body = new FormData();

    body.append('category', input.category);
    body.append('impact', input.impact);
    body.append('subject', input.subject.trim());
    body.append('description', input.description.trim());
    body.append('page', input.page);
    Object.entries(displayContext()).forEach(([key, value]) => {
        if (value !== '') {
            body.append(`context[${key}]`, value);
        }
    });
    input.screenshots.forEach((file) => body.append('screenshots[]', file));

    let response: Response;

    try {
        response = await fetch(problemReportRoutes.store, {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': xsrfToken(),
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body,
        });
    } catch {
        return {
            ok: false,
            message:
                'Couldn’t reach SYNAPSE. Check your connection, then send it again.',
            errors: {},
        };
    }

    const data = (await response.json().catch(() => null)) as {
        data?: ProblemReport;
        message?: string;
        errors?: Record<string, string[]>;
    } | null;

    if (response.ok && data?.data) {
        return { ok: true, report: data.data };
    }

    const errors = Object.fromEntries(
        Object.entries(data?.errors ?? {}).map(([field, messages]) => [
            // screenshots.1 → screenshots: one message under the picker.
            field.startsWith('screenshots') ? 'screenshots' : field,
            messages[0],
        ]),
    );

    return {
        ok: false,
        errors,
        message:
            response.status === 419
                ? 'Your session expired. Refresh the page, then send it again.'
                : response.status === 429
                  ? 'You’ve sent several reports in a short time. Wait a few minutes, then send this one.'
                  : response.status === 413
                    ? 'Those screenshots are too large to send together. Remove one, then try again.'
                    : Object.keys(errors).length > 0
                      ? 'Check the highlighted fields.'
                      : 'The report didn’t go through. Try again in a moment.',
    };
}
