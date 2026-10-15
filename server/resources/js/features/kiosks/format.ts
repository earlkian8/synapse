/** "just now", "4 min ago", "3 h ago", "2 days ago" — for when a kiosk was last heard from. */
export function ago(iso: string | null): string {
    if (!iso) {
        return 'never';
    }

    const seconds = Math.max(0, (Date.now() - Date.parse(iso)) / 1000);
    const rtf = new Intl.RelativeTimeFormat(undefined, {
        numeric: 'auto',
        style: 'short',
    });

    if (seconds < 60) {
        return 'just now';
    }

    if (seconds < 3600) {
        return rtf.format(-Math.round(seconds / 60), 'minute');
    }

    if (seconds < 86400) {
        return rtf.format(-Math.round(seconds / 3600), 'hour');
    }

    return rtf.format(-Math.round(seconds / 86400), 'day');
}

/** "Oct 15, 2026" in the company's zone. */
export function day(iso: string | null, timeZone?: string): string {
    return iso
        ? new Date(iso).toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              timeZone,
          })
        : '—';
}

/** "9:42 AM" in the company's zone. */
export function time(iso: string | null, timeZone?: string): string {
    return iso
        ? new Date(iso).toLocaleTimeString(undefined, {
              hour: 'numeric',
              minute: '2-digit',
              timeZone,
          })
        : '—';
}

/** A pairing code as it is shown and read aloud: "7K3M-Q9TP". */
export function grouped(code: string): string {
    const half = Math.ceil(code.length / 2);

    return `${code.slice(0, half)}-${code.slice(half)}`;
}
