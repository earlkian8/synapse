import { router } from '@inertiajs/react';
import { eventRoutes } from './routes';
import type { AttendeeResponse } from './types';

type Handlers = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

const opts = (h: Handlers = {}) => ({
    preserveScroll: true,
    onStart: h.onStart,
    onFinish: h.onFinish,
    onSuccess: h.onSuccess,
    onError: h.onError,
});

/** Invite one or more employees to an event. */
export function inviteAttendees(
    eventHashid: string,
    employeeIds: number[],
    h: Handlers = {},
): void {
    router.post(
        eventRoutes.invite(eventHashid),
        { employee_ids: employeeIds },
        opts(h),
    );
}

/** Update an invitee's response. */
export function updateAttendeeResponse(
    id: number,
    response: AttendeeResponse,
    h: Handlers = {},
): void {
    router.patch(eventRoutes.attendee(id), { response }, opts(h));
}

/** Remove an invitee from an event. */
export function removeAttendee(id: number, h: Handlers = {}): void {
    router.delete(eventRoutes.attendee(id), opts(h));
}
