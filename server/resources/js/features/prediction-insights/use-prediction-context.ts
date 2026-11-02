import { useEffect, useState } from 'react';
import type { PredictionContext, Surface } from './types';

/** Each surface's URL prefix. */
const PREFIX: Record<Surface, string> = {
    attrition: '/analytics/attrition',
    promotion: '/analytics/promotion-readiness',
    performance: '/analytics/performance-forecast',
};

/** Where each surface's page opens one person's breakdown. */
export function personHref(surface: Surface, employeeId: number): string {
    return `${PREFIX[surface]}?person=${employeeId}`;
}

export type PredictionContextState =
    | { status: 'loading'; context: null; retry: () => void }
    | { status: 'ready'; context: PredictionContext; retry: () => void }
    | { status: 'failed'; context: null; retry: () => void };

/**
 * The context of one person's prediction — trend, other predictions, record —
 * fetched when their breakdown opens (ADR 0099), so the page itself stays light.
 * A person seen once in a visit is not fetched again; a failed load can be retried.
 */
export function usePredictionContext(
    surface: Surface,
    employeeId: number | null,
): PredictionContextState {
    const key = employeeId === null ? null : `${surface}:${employeeId}`;
    const [loaded, setLoaded] = useState<
        Record<string, PredictionContext | 'failed'>
    >({});

    useEffect(() => {
        if (key === null || employeeId === null || loaded[key] !== undefined) {
            return;
        }

        const controller = new AbortController();

        fetch(`${PREFIX[surface]}/people/${employeeId}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) =>
                response.ok
                    ? (response.json() as Promise<PredictionContext>)
                    : Promise.reject(new Error(String(response.status))),
            )
            .then((context) =>
                setLoaded((current) => ({ ...current, [key]: context })),
            )
            .catch((error: unknown) => {
                if ((error as Error).name !== 'AbortError') {
                    setLoaded((current) => ({ ...current, [key]: 'failed' }));
                }
            });

        return () => controller.abort();
    }, [key, employeeId, surface, loaded]);

    const value = key === null ? undefined : loaded[key];
    // Forget a failure, so the effect fetches again.
    const retry = () =>
        setLoaded((current) => {
            if (key === null || current[key] !== 'failed') {
                return current;
            }

            const next = { ...current };
            delete next[key];

            return next;
        });

    if (value === undefined) {
        return { status: 'loading', context: null, retry };
    }

    return value === 'failed'
        ? { status: 'failed', context: null, retry }
        : { status: 'ready', context: value, retry };
}
