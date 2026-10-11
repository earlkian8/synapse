import { useAuth } from '@/lib/auth';

/**
 * The clock the signed-in person's days are kept on (ADR 0079): their base
 * site's, when it keeps its own zone, otherwise the company's — whatever zone
 * the phone is in. A recorded day carries the zone it was judged on
 * (`record.timezone`); prefer that for its times.
 */
export function useClockZone(): string | undefined {
  const { user, organization } = useAuth();

  return user?.employee?.timezone ?? organization?.timezone ?? undefined;
}
