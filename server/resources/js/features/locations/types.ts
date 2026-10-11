import type { TimezoneOption } from '@/components/timezone-select';

/** A work location (ADR 0040): a site, its fence, and what its people default to. */
export type WorkLocation = {
    id: number;
    hashid: string;
    name: string;
    address: string | null;
    latitude: number;
    longitude: number;
    radius_meters: number;
    /** A circle, or a shape drawn corner by corner (ADR 0079). */
    fence_type: 'circle' | 'polygon';
    /** The shape's corners; null for a circle. */
    polygon: Corner[] | null;
    /** "150 m circle", "Shape · 6 corners · 1.2 ha". */
    fence_label: string;
    /** The clock the site keeps; null = the company's. */
    timezone: string | null;
    default_work_schedule_id: number | null;
    attendance_policy_id: number | null;
    schedule_name?: string | null;
    policy_name?: string | null;
    is_active: boolean;
    is_archived: boolean;
    employees_count: number;
    /** Who is based here — the active listing only. */
    people?: { id: number; is_primary: boolean }[];
};

export type LocationEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    department_id: number | null;
    photo: string | null;
};

export type Ref = { id: number; name: string };

/** A shape's corner: `[latitude, longitude]`. */
export type Corner = [number, number];

/** A fence being drawn: where, and how wide. */
export type Fence = {
    latitude: number;
    longitude: number;
    radius_meters: number;
};

export type LocationsPageProps = {
    locations: WorkLocation[];
    archivedLocations: WorkLocation[];
    options: {
        schedules: Ref[];
        policies: Ref[];
        departments: Ref[];
        employees: LocationEmployee[];
        /** The zones a site may keep (ADR 0079). */
        timezones: TimezoneOption[];
        /** The company's own zone, which a site keeps unless told otherwise. */
        organizationTimezone: string;
    };
    /** Policies that check where people punch. */
    checkingPolicies: { name: string; mode: 'flag' | 'block' }[];
    can: { manage: boolean };
};
