import { useCallback, useState } from 'react';

/**
 * A layout preference (e.g. table vs. grid) remembered per browser via
 * localStorage. Generic so each board can keep its own choice under its own key.
 */
export function useStoredView<T extends string>(
    storageKey: string,
    allowed: readonly T[],
    fallback: T,
) {
    const read = (): T => {
        if (typeof window === 'undefined') {
            return fallback;
        }

        const saved = window.localStorage.getItem(storageKey);

        return saved && (allowed as readonly string[]).includes(saved)
            ? (saved as T)
            : fallback;
    };

    const [view, setView] = useState<T>(read);

    const changeView = useCallback(
        (next: T) => {
            setView(next);

            if (typeof window !== 'undefined') {
                window.localStorage.setItem(storageKey, next);
            }
        },
        [storageKey],
    );

    return { view, changeView };
}
