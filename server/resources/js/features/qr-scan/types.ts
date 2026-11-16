import type { QrPunchType } from '@/features/qr-stations/types';

/**
 * Scanning a QR station with a phone's own camera (ADR 0103) — shaped as
 * QrWebScanController answers.
 */
export type QrScanPageProps = {
    state: 'ready' | 'invalid' | 'refused' | 'app_only';
    message: string | null;
    station: { name: string; site: string | null; timezone: string } | null;
    organization: { name: string; logo_url: string | null } | null;
    /** Held for this browser alone while the person says who they are. */
    claim?: string;
    expires_in?: number;
    /** The app's own link to the code, offered at an app-only station. */
    app_link?: string;
    pin_length: number;
    max_accuracy: number;
};

export type QrDay = {
    next_expected: QrPunchType | null;
    allowed: QrPunchType[];
    selfie_required: boolean;
};

export type QrIdentified = {
    ticket: string;
    expires_in: number;
    first_name: string;
    must_change_pin: boolean;
    day: QrDay;
};

export type QrPunched = {
    type: QrPunchType;
    at: string;
    first_name: string;
    next_expected: QrPunchType | null;
};

export type QrRefusal = {
    message: string;
    reason?: string;
    errors?: Record<string, string[]>;
};
