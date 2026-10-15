import { useEffect, useState } from 'react';

/**
 * The time the kiosk shows: the server's, not the tablet's. A tablet on a wall
 * is rarely set right, and a worker reading 8:02 there should be stamped 8:02.
 * `offset` is the server's clock minus the tablet's, refreshed by every
 * heartbeat; this ticks once a second on it.
 */
export function useKioskClock(offset: number): Date {
    const [now, setNow] = useState(() => new Date(Date.now() + offset));

    useEffect(() => {
        const tick = () => setNow(new Date(Date.now() + offset));
        const id = window.setInterval(tick, 1000);

        return () => window.clearInterval(id);
    }, [offset]);

    return now;
}

/** The calendar and clock fields an instant shows in a zone. */
export function zoned(date: Date, timeZone: string) {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone,
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    }).formatToParts(date);

    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((entry) => entry.type === type)?.value ?? '';

    return {
        hour: part('hour'),
        minute: part('minute'),
        second: part('second'),
        period: part('dayPeriod'),
        weekday: part('weekday'),
        date: `${part('month')} ${part('day')}`,
        hour24: Number(
            new Intl.DateTimeFormat('en-US', {
                timeZone,
                hour: 'numeric',
                hourCycle: 'h23',
            }).format(date),
        ),
    };
}

/** "8:05 AM" on the kiosk's clock. */
export function clockTime(iso: string, timeZone: string): string {
    return new Date(iso).toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
    });
}
