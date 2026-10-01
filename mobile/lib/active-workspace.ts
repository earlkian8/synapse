/**
 * A tiny external store holding the id of the active workspace.
 *
 * Switching workspaces changes which company's token the {@link api} client
 * sends, so every tenant-scoped screen must refetch. Rather than couple each
 * screen (or {@link useQuery}) to the auth context, the auth layer publishes the
 * active id here and {@link useQuery} subscribes — so a switch refreshes all data
 * in place, with no per-screen wiring and no navigation reset.
 */
let current: string | null = null;
const listeners = new Set<() => void>();

/** Publish the active workspace id (called by the auth layer when it changes). */
export function setActiveWorkspaceId(id: string | null): void {
  if (id === current) return;
  current = id;
  for (const listener of listeners) listener();
}

/** Read the active workspace id (snapshot for `useSyncExternalStore`). */
export function getActiveWorkspaceId(): string | null {
  return current;
}

/** Subscribe to active-workspace changes; returns an unsubscribe function. */
export function subscribeActiveWorkspace(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}
