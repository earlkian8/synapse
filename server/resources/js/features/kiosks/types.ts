/**
 * Attendance → Kiosks (ADR 0092), shaped as KiosksScreen sends it.
 */

export type KioskStatus = 'unpaired' | 'pairing' | 'paired';

export type ManagedKiosk = {
    hashid: string;
    name: string;
    location: { id: number; name: string; archived: boolean } | null;
    status: KioskStatus;
    /** Heard from in the last few minutes. */
    online: boolean;
    /** "Chrome on Android" — the device it is paired with. */
    device_label: string | null;
    paired_at: string | null;
    last_seen_at: string | null;
    /** When the code waiting to be typed stops working. */
    pairing_expires_at: string | null;
    punches_today: number;
};

/** `new` — still the PIN HR issued; `active` — their own; `locked` — too many wrong tries. */
export type PinStatus = 'new' | 'active' | 'locked';

export type PinHolder = {
    id: number;
    status: PinStatus;
    issued_at: string | null;
    issued_by: string | null;
    changed_at: string | null;
    last_used_at: string | null;
    locked_until: string | null;
    /** They have resigned or been let go since; their PIN works nowhere. */
    left: boolean;
    employee: {
        id: number;
        full_name: string;
        initials: string;
        employee_no: string;
        photo: string | null;
        department: string | null;
    };
};

export type PinCandidate = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    department_id: number | null;
    photo: string | null;
    /** Whether they use the app: the question HR is asking. */
    app_access: 'active' | 'invited' | 'none';
    has_pin: boolean;
};

export type KiosksPageProps = {
    kiosks: ManagedKiosk[];
    pins: PinHolder[];
    options: {
        locations: { id: number; name: string }[];
        departments: { id: number; name: string }[];
        employees: PinCandidate[];
    };
    policiesWithoutKiosk: string[];
    kioskUrl: string;
    can: { viewPolicies: boolean };
};

export type PairingCode = {
    code: string;
    expires_at: string;
    minutes: number;
    url: string;
};

export type IssuedPin = {
    full_name: string;
    employee_no: string;
    pin: string;
    replaced: boolean;
};
