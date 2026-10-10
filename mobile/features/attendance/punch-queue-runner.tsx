import { useEffect } from 'react';
import { AppState } from 'react-native';

import { useToast } from '@/components/ui/toast';
import { useAuth } from '@/lib/auth';
import { formatTime } from '@/lib/format';

import { PUNCH_META } from './punch-meta';
import { punchQueue } from './punch-queue';

/** How often a waiting punch is tried again while the app is open. */
const RETRY_MS = 30_000;

/**
 * Sends punches queued while offline (ADR 0040): now, every half minute, and
 * whenever the app comes back to the foreground — so a punch made in a dead spot
 * arrives without anybody having to remember it. A punch the server refuses (too
 * old, out of order) is dropped and the person told, since only HR entering the
 * time can put it right. The refusal is told here, whoever sent it — this runner,
 * or the Clock screen's Send button.
 */
export function PunchQueueRunner() {
  const { user, organization } = useAuth();
  const toast = useToast();
  const timeZone = organization?.timezone;

  useEffect(
    () =>
      punchQueue.onRefused((refused) => {
        for (const { punch, message } of refused) {
          toast.show(
            `${PUNCH_META[punch.type].label} at ${formatTime(punch.punched_at, timeZone)} couldn’t be recorded: ${message}`,
            'error',
          );
        }
      }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [timeZone],
  );

  useEffect(() => {
    const send = () => void punchQueue.flush();

    void punchQueue
      .bind(user && organization ? { userId: user.id, organizationId: organization.id } : null)
      .then(send);

    const interval = setInterval(send, RETRY_MS);
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active') {
        send();
      }
    });

    return () => {
      clearInterval(interval);
      subscription.remove();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.id, organization?.id]);

  return null;
}
