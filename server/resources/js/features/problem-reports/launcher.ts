import { useSyncExternalStore } from 'react';

/**
 * Whether the Report a problem dialog is open, and a way for any page to open
 * it. The dialog is mounted once in the layout; the Help menu, the Help
 * Center and Your reports open it through this tiny store — the same shape as
 * the assistant's launcher.
 */
let isOpen = false;

const listeners = new Set<() => void>();

function setOpen(next: boolean): void {
    if (next === isOpen) {
        return;
    }

    isOpen = next;
    listeners.forEach((listener) => listener());
}

export const problemReportLauncher = {
    open: () => setOpen(true),
    close: () => setOpen(false),
};

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function useProblemReportOpen(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => isOpen,
        () => false,
    );
}
