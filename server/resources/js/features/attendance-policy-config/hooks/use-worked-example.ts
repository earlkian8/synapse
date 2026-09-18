import { useEffect, useState } from 'react';
import { previewDay } from '../api';
import type { DayVerdict, PolicySettings, SampleDay } from '../types';

type Answer = {
    /** Which settings + sample this answer is for. */
    key: string;
    result: DayVerdict | null;
    error: string | null;
};

/** How long the editor waits after the last change before asking. */
const DEBOUNCE_MS = 350;

/**
 * The worked example's verdict for the settings and sample on screen, asked of
 * the server's own evaluator (ADR 0038) a moment after the owner stops typing.
 *
 * Each answer remembers what it was an answer to, so "working it out" is simply
 * "the answer on hand is for something else" — no loading flag is set from the
 * effect, and a slow reply to an older question can never overwrite a newer one
 * (the older request is aborted).
 */
export function useWorkedExample(settings: PolicySettings, sample: SampleDay) {
    const key = JSON.stringify([settings, sample]);
    const [answer, setAnswer] = useState<Answer | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        const [askedSettings, askedSample] = JSON.parse(key) as [
            PolicySettings,
            SampleDay,
        ];

        const timer = window.setTimeout(() => {
            previewDay(askedSettings, askedSample, controller.signal)
                .then((outcome) =>
                    setAnswer({
                        key,
                        result: outcome.ok ? outcome.result : null,
                        error: outcome.ok ? null : outcome.message,
                    }),
                )
                .catch(() => {
                    // Aborted — a newer question is already on its way.
                });
        }, DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [key]);

    return {
        /** The latest verdict, kept on screen while a newer one is worked out. */
        result: answer?.result ?? null,
        error: answer?.key === key ? answer.error : null,
        pending: answer?.key !== key,
    };
}
