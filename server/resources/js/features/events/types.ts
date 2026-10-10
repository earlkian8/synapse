export type EventType = 'event' | 'meeting';

export type EventStatus = 'upcoming' | 'ongoing' | 'past';

export type AttendeeResponse =
    'invited' | 'accepted' | 'declined' | 'tentative';

export type EventEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    position: string | null;
    department: string | null;
};

export type EventAttendee = {
    id: number;
    response: AttendeeResponse;
    responded_at: string | null;
    notified_at: string | null;
    employee?: EventEmployee | null;
};

export type EventOrganizer = {
    id: number;
    name: string;
};

/** A room an event can hold (ADR 0070). */
export type EventRoom = {
    id: number;
    hashid: string;
    name: string;
    location: string | null;
    capacity: number | null;
};

/** The repeat an occurrence belongs to, and where it sits in it. */
export type EventSeriesInfo = {
    id: number;
    summary: string;
    position: number | null;
    total: number | null;
};

/** Whether a change to an occurrence covers it alone or every later one too. */
export type EventScope = 'this' | 'following';

export type RepeatFrequency = 'daily' | 'weekly' | 'monthly';

/** The repeat rule the form sends when scheduling. */
export type RepeatRule = {
    frequency: RepeatFrequency;
    interval: number;
    weekdays: number[];
    until: string | null;
    count: number | null;
};

export type EventItem = {
    id: number;
    hashid: string;
    title: string;
    description: string | null;
    type: EventType;
    starts_at: string | null;
    ends_at: string | null;
    location: string | null;
    status: EventStatus;
    is_archived: boolean;
    reminder_minutes: number | null;
    reminder_sent_at: string | null;
    room?: EventRoom | null;
    series?: EventSeriesInfo | null;
    organizer?: EventOrganizer | null;
    attendees_count: number;
    attending_count: number;
    attendees?: EventAttendee[];
};

export type EventStats = {
    total: number;
    upcoming: number;
    meetings: number;
    invitations: number;
};

export type EventPermissions = { manage: boolean };

export type InvitableEmployee = {
    id: number;
    full_name: string;
    employee_no: string;
};

export type EventIndexPageProps = {
    events: EventItem[];
    archived: EventItem[];
    stats: EventStats;
    can: EventPermissions;
};

export type EventShowPageProps = {
    event: EventItem;
    invitable: InvitableEmployee[];
    can: EventPermissions;
};

// ── My events (self-service) ────────────────────────────────────────────────

/** An answer an invitee gives themselves. */
export type InviteeAnswer = 'accepted' | 'tentative' | 'declined';

export type MyInvitation = {
    id: number;
    response: AttendeeResponse;
    responded_at: string | null;
    event: {
        hashid: string;
        title: string;
        description: string | null;
        type: EventType;
        starts_at: string | null;
        ends_at: string | null;
        location: string | null;
        status: EventStatus;
        reminder_minutes: number | null;
        room: { name: string; location: string | null } | null;
        series: { id: number; summary: string } | null;
        organizer: string | null;
        attendees_count: number;
        attending_count: number;
    };
};

export type MyEventsPageProps = {
    invitations: MyInvitation[];
    focus: string | null;
    has_employee: boolean;
};

export type CalendarLinks = { https: string; webcal: string };

// ── Rooms ───────────────────────────────────────────────────────────────────

export type RoomItem = EventRoom & {
    description: string | null;
    is_active: boolean;
    is_archived: boolean;
};

export type RoomBooking = {
    hashid: string;
    title: string;
    type: EventType;
    starts_at: string | null;
    ends_at: string | null;
    repeats: boolean;
};

export type RoomsPageProps = {
    rooms: (RoomItem & { bookings: RoomBooking[] })[];
    archived: RoomItem[];
    week: { start: string; previous: string; next: string };
    can: EventPermissions;
};

/** One room's answer from the availability check. */
export type RoomAvailability = RoomItem & {
    free: boolean;
    clash: {
        title: string;
        starts_at: string | null;
        ends_at: string | null;
    } | null;
};
