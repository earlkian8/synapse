import { router } from '@inertiajs/react';
import type { Coordinates } from './hooks/use-clock';
import { attendanceRoutes } from './routes';
import type { PunchType } from './types';

type PunchOptions = {
    coords?: Coordinates | null;
    photo?: File | null;
    note?: string | null;
    onStart?: () => void;
    onFinish?: () => void;
};

/**
 * Record one of my own punches (web self-service). Inertia upgrades the request
 * to multipart automatically when a selfie file is attached.
 */
export function punch(type: PunchType, options: PunchOptions = {}): void {
    const { coords, photo, note, onStart, onFinish } = options;

    const payload: Record<string, string | number | File> = { type };

    if (coords) {
        payload.latitude = coords.latitude;
        payload.longitude = coords.longitude;
        payload.accuracy = coords.accuracy;
    }

    if (photo) {
        payload.photo = photo;
    }

    if (note) {
        payload.note = note;
    }

    router.post(attendanceRoutes.mePunch, payload, {
        preserveScroll: true,
        onStart,
        onFinish,
    });
}
