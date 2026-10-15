/**
 * The kiosk device's side of ADR 0092 — shaped as KioskPresenter answers it.
 */

export type KioskPunchType =
    'clock_in' | 'break_start' | 'break_end' | 'clock_out';

export type KioskInfo = {
    name: string;
    site: string | null;
    organization: {
        name: string | null;
        logo_url: string | null;
        initials: string | null;
    };
    /** The IANA zone the kiosk's clock keeps: its site's, or the company's. */
    timezone: string;
    pin_length: number;
    /** The server's time when it answered — the kiosk's clock follows it. */
    now: string;
};

/** Which screen the page opens on (KioskDeviceController::show). */
export type KioskPageState = 'ready' | 'unpaired' | 'signed_in';

export type KioskPageProps = {
    state: KioskPageState;
    kiosk: KioskInfo | null;
    codeLength: number;
};

export type KioskDay = {
    work_date: string;
    /** "Shift 8:00 AM – 5:00 PM", "8h to work today", or null on a day off. */
    shift: string | null;
    next_expected: KioskPunchType | null;
    allowed: KioskPunchType[];
    punches: { type: KioskPunchType; at: string }[];
    /** False when their policy would turn a kiosk punch away. */
    kiosk_allowed: boolean;
    photo_required: boolean;
};

/** Somebody signed in at the kiosk with their number and PIN. */
export type KioskSession = {
    ticket: string;
    expires_in: number;
    must_change_pin: boolean;
    person: {
        first_name: string;
        full_name: string;
        initials: string;
    };
    day: KioskDay;
};

export type KioskPunchResult = {
    message: string;
    type: KioskPunchType;
    at: string;
    first_name: string;
    day: KioskDay;
};

/** Why a request was turned away, when the kiosk should act on it. */
export type KioskRefusal =
    'unpaired' | 'signed_in' | 'expired' | 'must_change_pin';
