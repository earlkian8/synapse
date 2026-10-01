/**
 * A way for any page to open the assistant — optionally with a question already
 * typed for the person to finish and send. The assistant is mounted once in the
 * layout, so this is a tiny module-level channel rather than shared state: the
 * Help Center's "Ask the assistant" calls `open`, the assistant listens.
 *
 * Nothing is sent on the person's behalf: a turn spends model quota, so the
 * question waits in the composer until they press Send.
 */
type LaunchRequest = { prompt: string };

const listeners = new Set<(request: LaunchRequest) => void>();

export const assistantLauncher = {
    open(prompt = ''): void {
        listeners.forEach((listener) => listener({ prompt }));
    },

    subscribe(listener: (request: LaunchRequest) => void): () => void {
        listeners.add(listener);

        return () => listeners.delete(listener);
    },
};
