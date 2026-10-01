import { useEffect, useState } from 'react';

export type Coordinates = {
    latitude: number;
    longitude: number;
    accuracy: number;
};

/**
 * A live wall-clock that ticks every second. The interval is an external source
 * synced into state — the one legitimate place to set state from an effect.
 */
export function useNow(): Date {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const id = window.setInterval(() => setNow(new Date()), 1000);

        return () => window.clearInterval(id);
    }, []);

    return now;
}

/**
 * Resolve the device's current position, or null if unavailable/denied. Never
 * rejects — a punch should still go through without coordinates.
 */
export function getCurrentPosition(): Promise<Coordinates | null> {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (position) =>
                resolve({
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy: position.coords.accuracy,
                }),
            () => resolve(null),
            { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 },
        );
    });
}
