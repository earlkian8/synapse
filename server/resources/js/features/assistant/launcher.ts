import { useSyncExternalStore } from 'react';

/**
 * Whether the assistant's panel is open, and a way for any page to open it —
 * optionally with a question already typed for the person to finish and send.
 *
 * The panel is mounted once in the layout and its button lives in the top bar,
 * so the two share this tiny module-level store rather than React state: the
 * button reads it to show the panel's state, the Help Center's "Ask the
 * assistant" calls `open`, and the panel listens for the question.
 *
 * Nothing is sent on the person's behalf: a turn spends model quota, so the
 * question waits in the composer until they press Send.
 */
type LaunchRequest = { prompt: string };

let isOpen = false;

const openListeners = new Set<() => void>();
const launchListeners = new Set<(request: LaunchRequest) => void>();

function setOpen(next: boolean): void {
    if (next === isOpen) {
        return;
    }

    isOpen = next;
    openListeners.forEach((listener) => listener());
}

export const assistantLauncher = {
    open(prompt = ''): void {
        setOpen(true);
        launchListeners.forEach((listener) => listener({ prompt }));
    },

    close(): void {
        setOpen(false);
    },

    toggle(): void {
        setOpen(!isOpen);
    },

    /** Hear each `open` call, with the question it brought (if any). */
    subscribe(listener: (request: LaunchRequest) => void): () => void {
        launchListeners.add(listener);

        return () => launchListeners.delete(listener);
    },
};

function subscribeOpen(listener: () => void): () => void {
    openListeners.add(listener);

    return () => openListeners.delete(listener);
}

/** Whether the panel is open — the same answer for the button and the panel. */
export function useAssistantOpen(): boolean {
    return useSyncExternalStore(
        subscribeOpen,
        () => isOpen,
        () => false,
    );
}
