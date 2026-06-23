export type EventType = 'event' | 'meeting';

export type EventStatus = 'upcoming' | 'ongoing' | 'past';

export type AttendeeResponse =
    | 'invited'
    | 'accepted'
    | 'declined'
    | 'tentative';

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
    notified_at: string | null;
    employee?: EventEmployee | null;
};

export type EventOrganizer = {
    id: number;
    name: string;
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
