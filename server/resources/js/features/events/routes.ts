/**
 * Endpoint map for the Events & Meetings module.
 * Mirrors the named routes in routes/events.php. Events are addressed by hashid;
 * attendees by numeric id. Restore / force-delete take the hashid as a plain string.
 */
export const eventRoutes = {
    index: '/events',
    store: '/events',
    show: (hashid: string) => `/events/${hashid}`,
    update: (hashid: string) => `/events/${hashid}`,
    destroy: (hashid: string) => `/events/${hashid}`,
    restore: (hashid: string) => `/events/${hashid}/restore`,
    forceDelete: (hashid: string) => `/events/${hashid}/force`,
    invite: (hashid: string) => `/events/${hashid}/attendees`,
    attendee: (id: number) => `/events/attendees/${id}`,
} as const;
