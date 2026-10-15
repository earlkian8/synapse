import { Coffee, LogIn, LogOut, Play } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { CSSProperties } from 'react';
import type { KioskPunchType } from './types';

/**
 * The kiosk's palette (ADR 0092). The kiosk is a device, not a page in the app:
 * it keeps one look whatever the browser's theme. Navy is the brand's ground;
 * the card is the time card a punch is stamped on, its rules teal-tinted; the
 * inks are a time card's — teal for in, red for out, amber for a break.
 */
export const KIOSK_TOKENS = {
    '--k-ground': '#0F2044',
    '--k-ground-raised': '#172C5A',
    '--k-paper': '#FBFBF8',
    '--k-rule': '#D6E4E5',
    '--k-ink': '#0F2044',
    '--k-muted': '#566683',
    '--k-in': '#08767C',
    '--k-out': '#B4233A',
    '--k-break': '#B45309',
    '--k-signal': '#0ABFBF',
} as CSSProperties;

type PunchWords = {
    /** The button: what it does. */
    action: string;
    /** The time card's column for it. */
    column: string;
    /** What the kiosk says once it is stamped. */
    done: string;
    icon: LucideIcon;
    /** Its ink, as a CSS colour. */
    ink: string;
};

export const PUNCH_WORDS: Record<KioskPunchType, PunchWords> = {
    clock_in: {
        action: 'Clock in',
        column: 'In',
        done: 'Clocked in',
        icon: LogIn,
        ink: 'var(--k-in)',
    },
    break_start: {
        action: 'Start break',
        column: 'Break',
        done: 'Break started',
        icon: Coffee,
        ink: 'var(--k-break)',
    },
    break_end: {
        action: 'End break',
        column: 'Back',
        done: 'Back from break',
        icon: Play,
        ink: 'var(--k-break)',
    },
    clock_out: {
        action: 'Clock out',
        column: 'Out',
        done: 'Clocked out',
        icon: LogOut,
        ink: 'var(--k-out)',
    },
};

/** What the stamped card says to the person, by what they just did. */
export function sendOff(type: KioskPunchType, firstName: string): string {
    switch (type) {
        case 'clock_in':
            return `Have a good shift, ${firstName}.`;
        case 'break_start':
            return `Enjoy your break, ${firstName}.`;
        case 'break_end':
            return `Welcome back, ${firstName}.`;
        case 'clock_out':
            return `See you next shift, ${firstName}.`;
    }
}

/** "Good morning" by the kiosk's own clock. */
export function greeting(hour: number): string {
    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 18 ? 'Good afternoon' : 'Good evening';
}
