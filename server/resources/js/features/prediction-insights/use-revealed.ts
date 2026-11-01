import { useEffect, useState } from 'react';

/** Whether the visitor prefers reduced motion (read once, at module scope). */
const reduceMotion =
    typeof window !== 'undefined' &&
    window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/**
 * Drives a one-shot reveal: false on first paint, true a frame later (and true from
 * the start when the visitor prefers reduced motion), so marks can grow in once.
 */
export function useRevealed(): boolean {
    const [revealed, setRevealed] = useState(reduceMotion);

    useEffect(() => {
        if (reduceMotion) {
            return;
        }

        const id = requestAnimationFrame(() => setRevealed(true));

        return () => cancelAnimationFrame(id);
    }, []);

    return revealed;
}
