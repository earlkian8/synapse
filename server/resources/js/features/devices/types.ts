/** A kiosk or biometric scanner that sends punches (ADR 0040). */
export type AttendanceDevice = {
    id: number;
    hashid: string;
    name: string;
    type: 'kiosk' | 'biometric';
    work_location_id: number | null;
    location_name?: string | null;
    /** The key's last four characters — never the key. */
    key_hint: string;
    last_seen_at: string | null;
    last_seen_human: string | null;
    is_active: boolean;
    punches_count: number;
    /** How its CSV export's columns map, once chosen. */
    csv_mapping: CsvMapping | null;
};

export type CsvField =
    'employee_ref' | 'punched_at' | 'date' | 'time' | 'type' | 'external_id';

export type CsvMapping = Partial<Record<CsvField, string | null>>;

/** Flashed once, when a device is registered or its key replaced. */
export type IssuedKey = {
    device: string;
    name: string;
    type: 'kiosk' | 'biometric';
    key: string;
};

/** Flashed after a CSV import: the totals and the rows that need attention. */
export type ImportResult = {
    device: string;
    accepted: number;
    duplicates: number;
    rejected: number;
    issues: { line: number; message: string }[];
    problems: string[];
};

export type DevicesPageProps = {
    devices: AttendanceDevice[];
    locations: { id: number; name: string }[];
    endpoints: { punches: string; kiosk: string };
    can: { manage: boolean };
};
