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

    // My events (ADR 0070).
    me: '/events/me',
    meFocus: (hashid: string) => `/events/me?event=${hashid}`,
    meRespond: (hashid: string) => `/events/me/${hashid}/respond`,
    meIcs: (hashid: string) => `/events/me/${hashid}/ics`,
    meCalendar: '/events/me/calendar',
    meCalendarReset: '/events/me/calendar/reset',

    // Rooms (ADR 0070). Addressed by hashid.
    rooms: '/events/rooms',
    roomsWeek: (week: string) => `/events/rooms?week=${week}`,
    roomAvailability: '/events/rooms/availability',
    roomStore: '/events/rooms',
    roomUpdate: (hashid: string) => `/events/rooms/${hashid}`,
    roomDestroy: (hashid: string) => `/events/rooms/${hashid}`,
    roomRestore: (hashid: string) => `/events/rooms/${hashid}/restore`,
    roomForceDelete: (hashid: string) => `/events/rooms/${hashid}/force`,
} as const;
