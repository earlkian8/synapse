import { useEffect, useRef, useState } from 'react';
import type { QrCodeAnswer, QrMatrix } from '../types';

export type QrScreenState =
    | { kind: 'loading' }
    | {
          kind: 'live';
          matrix: QrMatrix;
          /** When the code stops being the one to scan, on the server's clock. */
          expiresAt: number;
          rotateSeconds: number;
          /** A fresh value per code, to restart the countdown. */
          key: string;
      }
    | { kind: 'inactive' }
    | { kind: 'no_site' }
    /** No code on hand for now, and the server can't be reached. */
    | { kind: 'offline' }
    /** The account that opened the screen can no longer show it. */
    | { kind: 'signed_out' };

type Held = {
    window: number;
    startsAt: number;
    expiresAt: number;
    matrix: QrMatrix;
};

type Status = 'loading' | 'live' | 'inactive' | 'no_site' | 'signed_out';

/** How long to wait before asking again after a failed ask, growing. */
const RETRY_MS = [3000, 6000, 15000, 30000];

/** How often a station that is off or has no site asks whether it is back. */
const IDLE_MS = 60_000;

/**
 * The station's codes (ADR 0103), asked for a few minutes at a time and shown
 * each in its own window. The screen holds no secret: it is sent signed codes
 * and when each one shows. One ask every `refresh_in` seconds (two minutes)
 * keeps it supplied, however fast the code changes — the cost of a screen on a
 * wall is about thirty requests an hour, and none while it's hidden.
 *
 * A code is never shown past its time: if the server can't be reached and the
 * codes on hand run out, the screen says so instead of leaving a dead code up.
 *
 * Also keeps the screen's clock on the server's (`offset`) and the latest
 * people the station clocked in (`feed`), refreshed with each ask.
 */
export function useQrCode(codeUrl: string) {
    const [status, setStatus] = useState<Status>('loading');
    const [codes, setCodes] = useState<Held[]>([]);
    const [rotateSeconds, setRotateSeconds] = useState(15);
    const [feed, setFeed] = useState<QrCodeAnswer['feed']>([]);
    const [offset, setOffset] = useState(0);
    const [now, setNow] = useState(() => Date.now());
    const offsetRef = useRef(0);

    // Asking: on open, every `refresh_in`, sooner after a failure, never while
    // the screen is hidden.
    useEffect(() => {
        let timer: number | undefined;
        let cancelled = false;
        let failures = 0;
        let controller: AbortController | null = null;

        const schedule = (ms: number) => {
            window.clearTimeout(timer);

            if (document.visibilityState === 'visible') {
                timer = window.setTimeout(ask, ms);
            }
        };

        async function ask() {
            controller?.abort();
            controller = new AbortController();
            let response: Response;

            try {
                response = await fetch(codeUrl, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });
            } catch (error) {
                if (
                    !cancelled &&
                    !(
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    )
                ) {
                    schedule(
                        RETRY_MS[Math.min(failures++, RETRY_MS.length - 1)],
                    );
                }

                return;
            }

            if (cancelled) {
                return;
            }

            if ([401, 403, 419].includes(response.status)) {
                setStatus('signed_out');

                return;
            }

            const answer = (await response
                .json()
                .catch(() => null)) as QrCodeAnswer | null;

            if (!response.ok || answer === null) {
                schedule(RETRY_MS[Math.min(failures++, RETRY_MS.length - 1)]);

                return;
            }

            failures = 0;
            const serverNow = Date.parse(answer.now);
            const drift = Number.isNaN(serverNow) ? 0 : serverNow - Date.now();
            offsetRef.current = drift;
            setOffset(drift);
            setFeed(answer.feed);
            setRotateSeconds(answer.rotate_seconds);

            if (answer.state !== 'live') {
                setStatus(answer.state);
                setCodes([]);
                schedule(IDLE_MS);

                return;
            }

            setStatus('live');
            setCodes((held) => {
                // Keep what is still to come from the last batch; the new one
                // wins where they overlap.
                const merged = new Map(
                    held
                        .filter((code) => code.expiresAt > Date.now() + drift)
                        .map((code) => [code.window, code]),
                );

                for (const code of answer.codes) {
                    merged.set(code.window, {
                        window: code.window,
                        startsAt: Date.parse(code.starts_at),
                        expiresAt: Date.parse(code.expires_at),
                        matrix: code.matrix,
                    });
                }

                return [...merged.values()].sort((a, b) => a.window - b.window);
            });

            setNow(Date.now());
            schedule(Math.max(10, answer.refresh_in) * 1000);
        }

        void ask();

        // A screen brought back into view asks at once; a hidden one stops.
        const visibility = () => {
            if (document.visibilityState === 'visible') {
                schedule(0);
            } else {
                window.clearTimeout(timer);
            }
        };

        document.addEventListener('visibilitychange', visibility);

        return () => {
            cancelled = true;
            controller?.abort();
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', visibility);
        };
    }, [codeUrl]);

    // Showing: wake at each code's change, on the server's clock.
    useEffect(() => {
        const serverNow = now + offsetRef.current;
        const next = codes.find((code) => code.expiresAt > serverNow);
        const wake = next
            ? (next.startsAt > serverNow ? next.startsAt : next.expiresAt) -
              serverNow
            : null;

        if (wake === null) {
            return;
        }

        const timer = window.setTimeout(
            () => setNow(Date.now()),
            Math.max(50, wake + 30),
        );

        return () => window.clearTimeout(timer);
    }, [codes, now]);

    const serverNow = now + offset;
    const current = codes.find(
        (code) => code.startsAt <= serverNow && code.expiresAt > serverNow,
    );

    const screen: QrScreenState =
        status === 'live'
            ? current
                ? {
                      kind: 'live',
                      matrix: current.matrix,
                      expiresAt: current.expiresAt,
                      rotateSeconds,
                      key: String(current.window),
                  }
                : { kind: 'offline' }
            : { kind: status };

    return { screen, feed, offset };
}
