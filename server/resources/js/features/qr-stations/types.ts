/**
 * QR stations (ADR 0103) — shaped as QrStationsScreen and
 * QrStationController answer them.
 */

export type QrScanMode = 'app' | 'any';

export type QrPunchType =
    'clock_in' | 'break_start' | 'break_end' | 'clock_out';

export type QrStation = {
    hashid: string;
    name: string;
    location: { id: number; name: string; archived: boolean } | null;
    rotate_seconds: number;
    /** `app` — the SYNAPSE app only; `any` — any phone's camera too, with number and PIN. */
    scan_mode: QrScanMode;
    /** Scans must come through the same internet connection as the screen. */
    same_network: boolean;
    is_active: boolean;
    /** Switched on and at a site that still exists: scans can be accepted. */
    usable: boolean;
    /** A screen asked for its codes in the last few minutes. */
    showing: boolean;
    last_shown_at: string | null;
    last_shown_by: string | null;
    accepted_today: number;
    refused_today: number;
    display_url: string;
};

export type QrRefusalReason =
    | 'expired'
    | 'invalid'
    | 'not_showing'
    | 'inactive'
    | 'no_site'
    | 'no_location'
    | 'imprecise'
    | 'mocked'
    | 'away'
    | 'shared_device'
    | 'no_device'
    | 'other_network'
    | 'app_only'
    | 'wrong_pin'
    | 'punch_refused';

export type QrScan = {
    id: number;
    at: string | null;
    station: string | null;
    outcome: 'accepted' | 'refused';
    /** Scanned with the app, or in a phone's browser with number and PIN. */
    client: 'app' | 'browser';
    reason: QrRefusalReason | null;
    reason_label: string | null;
    /** A refusal that looks like getting round the station. */
    suspicious: boolean;
    punch_type: QrPunchType | null;
    distance_meters: number | null;
    accuracy: number | null;
    employee: {
        id: number;
        full_name: string;
        initials: string;
        employee_no: string;
        photo: string | null;
    } | null;
};

export type QrStationsPageProps = {
    stations: QrStation[];
    scans: QrScan[];
    options: {
        locations: { id: number; name: string }[];
        rotateChoices: number[];
    };
    policiesWithoutQr: string[];
    /** Who has a kiosk PIN — what a scan without the app signs in with. */
    pins: { issued: number; employees: number } | null;
    can: {
        manage: boolean;
        display: boolean;
        viewPolicies: boolean;
        managePins: boolean;
    };
};

/** The station's screen (attendance/qr-display). */
export type QrDisplayProps = {
    station: {
        hashid: string;
        name: string;
        site: string | null;
        rotate_seconds: number;
        scan_mode: QrScanMode;
        code_url: string;
        timezone: string;
    };
    organization: { name: string | null; logo_url: string | null };
    back_url: string;
};

/** A code's modules, row by row, eight to a byte, base64 (QrImage::matrix). */
export type QrMatrix = { size: number; bits: string };

export type QrCodeAnswer = {
    state: 'live' | 'inactive' | 'no_site';
    /** The codes for the next few minutes, each with when it shows. */
    codes: {
        window: number;
        starts_at: string;
        expires_at: string;
        matrix: QrMatrix;
    }[];
    rotate_seconds: number;
    /** When to ask again, in seconds. */
    refresh_in: number;
    now: string;
    feed: {
        id: number;
        name: string;
        type: QrPunchType | null;
        at: string | null;
    }[];
};
