import { useSyncExternalStore } from 'react';

export type ResolvedAppearance = 'light' | 'dark';
export type Appearance = ResolvedAppearance | 'system';

/**
 * Light, dark or the system's — the ERP's appearance hook, kept in this
 * browser's storage only (the portal has no server to read a cookie).
 */
const STORAGE_KEY = 'synapse.admin.appearance';
const listeners = new Set<() => void>();
let currentAppearance: Appearance = 'system';

const prefersDark = (): boolean =>
    window.matchMedia('(prefers-color-scheme: dark)').matches;

const readStored = (): Appearance => {
    try {
        const value = localStorage.getItem(STORAGE_KEY);

        return value === 'light' || value === 'dark' ? value : 'system';
    } catch {
        return 'system';
    }
};

const isDarkMode = (appearance: Appearance): boolean =>
    appearance === 'dark' || (appearance === 'system' && prefersDark());

const applyTheme = (appearance: Appearance): void => {
    const isDark = isDarkMode(appearance);

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

export function initializeTheme(): void {
    currentAppearance = readStored();
    applyTheme(currentAppearance);

    window
        .matchMedia('(prefers-color-scheme: dark)')
        .addEventListener('change', () => {
            applyTheme(currentAppearance);
            listeners.forEach((listener) => listener());
        });
}

export function useAppearance() {
    const appearance = useSyncExternalStore(
        subscribe,
        () => currentAppearance,
        () => 'system' as Appearance,
    );

    const resolvedAppearance: ResolvedAppearance = isDarkMode(appearance)
        ? 'dark'
        : 'light';

    const updateAppearance = (mode: Appearance): void => {
        currentAppearance = mode;

        try {
            localStorage.setItem(STORAGE_KEY, mode);
        } catch {
            // Blocked storage: the choice lasts for this visit.
        }

        applyTheme(mode);
        listeners.forEach((listener) => listener());
    };

    return { appearance, resolvedAppearance, updateAppearance } as const;
}
