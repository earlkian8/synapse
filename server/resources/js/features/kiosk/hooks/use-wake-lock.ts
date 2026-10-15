import { useEffect } from 'react';

type WakeLockSentinelLike = { release: () => Promise<void> };
type WakeLockLike = {
    request: (type: 'screen') => Promise<WakeLockSentinelLike>;
};

/**
 * Keep the tablet's screen on while it is a kiosk. A browser lets go of the
 * lock whenever the page is hidden, so it is asked for again on return.
 * Browsers without the API simply sleep as they are set to.
 */
export function useWakeLock(enabled: boolean): void {
    useEffect(() => {
        const wakeLock = (navigator as Navigator & { wakeLock?: WakeLockLike })
            .wakeLock;

        if (!enabled || !wakeLock) {
            return;
        }

        let sentinel: WakeLockSentinelLike | null = null;

        const acquire = () => {
            if (document.visibilityState === 'visible') {
                wakeLock
                    .request('screen')
                    .then((granted) => {
                        sentinel = granted;
                    })
                    .catch(() => undefined);
            }
        };

        acquire();
        document.addEventListener('visibilitychange', acquire);

        return () => {
            document.removeEventListener('visibilitychange', acquire);
            void sentinel?.release().catch(() => undefined);
        };
    }, [enabled]);
}
