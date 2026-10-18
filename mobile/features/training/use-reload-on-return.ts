import { useFocusEffect } from 'expo-router';
import { useCallback, useRef } from 'react';

/**
 * Fetch again, quietly, when the screen comes back into view — back from a
 * program someone just joined or left, so the list already says so. The first
 * focus is the mount, which loads anyway.
 */
export function useReloadOnReturn(reload: () => Promise<void>) {
  const focusedBefore = useRef(false);

  useFocusEffect(
    useCallback(() => {
      if (focusedBefore.current) void reload();
      focusedBefore.current = true;
    }, [reload]),
  );
}
