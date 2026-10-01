import { usePage } from '@inertiajs/react';
import type { Auth } from '@/types/auth';

/**
 * The IANA zone the current organisation keeps (ADR 0036).
 *
 * Attendance times are shown on this clock rather than the viewer's, so a
 * manager travelling abroad sees the day that is actually being judged — an
 * 08:30 clock-in in Manila reads 08:30, not whatever it is in the hotel.
 * Undefined (the browser's own zone) only outside an organisation.
 */
export function useOrganizationTimeZone(): string | undefined {
    const { auth } = usePage<{ auth?: Auth }>().props;

    return auth?.organization?.timezone ?? undefined;
}
