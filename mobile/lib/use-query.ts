import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';

import { getActiveWorkspaceId, subscribeActiveWorkspace } from '@/lib/active-workspace';

type QueryState<T> = {
  data: T | null;
  loading: boolean;
  refreshing: boolean;
  error: string | null;
  refresh: () => Promise<void>;
  reload: () => Promise<void>;
};

/**
 * Minimal data hook: runs `fetcher` on mount and whenever a dep changes, and
 * exposes pull-to-refresh + manual reload with loading/error flags. Keeps every
 * list screen's boilerplate in one place.
 */
export function useQuery<T>(fetcher: () => Promise<T>, deps: unknown[] = []): QueryState<T> {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const run = useCallback(
    async (mode: 'initial' | 'refresh') => {
      if (mode === 'refresh') {
        setRefreshing(true);
      }

      setError(null);

      try {
        const result = await fetcher();
        setData(result);
      } catch (e) {
        setError(e instanceof Error ? e.message : 'Something went wrong.');
      } finally {
        setLoading(false);
        setRefreshing(false);
      }
      // eslint-disable-next-line react-hooks/exhaustive-deps
    },
    deps,
  );

  // Re-run when the caller's deps change or the active workspace is switched, so
  // every screen reloads against the newly-active company's tenant context.
  const activeWorkspace = useSyncExternalStore(subscribeActiveWorkspace, getActiveWorkspaceId, getActiveWorkspaceId);

  useEffect(() => {
    setLoading(true);
    void run('initial');
  }, [run, activeWorkspace]);

  return {
    data,
    loading,
    refreshing,
    error,
    refresh: () => run('refresh'),
    reload: () => run('initial'),
  };
}
