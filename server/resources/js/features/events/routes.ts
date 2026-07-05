/**
 * Endpoint map for the Events & Meetings module.
 * Mirrors the named routes in routes/events.php. Events are addressed by hashid;
 * attendees by numeric id. Restore / force-delete take the hashid as a plain string.
 */
export const eventRoutes = {
    index: '/events',
    store: '/events',
    export: '/events/export',
    show: (hashid: string) => `/events/${hashid}`,
    update: (hashid: string) => `/events/${hashid}`,
    destroy: (hashid: string) => `/events/${hashid}`,
    restore: (hashid: string) => `/events/${hashid}/restore`,
    forceDelete: (hashid: string) => `/events/${hashid}/force`,
    duplicate: (hashid: string) => `/events/${hashid}/duplicate`,
    rosterExport: (hashid: string) => `/events/${hashid}/export`,
    ics: (hashid: string) => `/events/${hashid}/ics`,
    invite: (hashid: string) => `/events/${hashid}/attendees`,
    remind: (hashid: string) => `/events/${hashid}/remind`,
    attendee: (id: number) => `/events/attendees/${id}`,
} as const;
