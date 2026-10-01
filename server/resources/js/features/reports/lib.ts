import {
    BarChart3,
    CalendarCheck,
    ClipboardList,
    ScrollText,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReportColumn } from './types';

/**
 * Map a badge label to a tone class set. Keyed on the canonical states across the
 * app (employment, leave, recruitment, movement) so a status reads the same colour
 * wherever it appears.
 */
export function badgeTone(value: string): string {
    const v = value.toLowerCase();

    const green = [
        'active',
        'approved',
        'hired',
        'completed',
        'present',
        'regular',
        'created',
    ];
    const amber = [
        'pending',
        'late',
        'on leave',
        'probationary',
        'screening',
        'interview',
        'offer',
        'updated',
    ];
    const red = [
        'rejected',
        'absent',
        'terminated',
        'resigned',
        'suspended',
        'separated',
        'cancelled',
        'deleted',
    ];

    if (green.some((t) => v.includes(t))) {
        return 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-400';
    }

    if (red.some((t) => v.includes(t))) {
        return 'bg-rose-500/10 text-rose-700 ring-rose-500/20 dark:text-rose-400';
    }

    if (amber.some((t) => v.includes(t))) {
        return 'bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-400';
    }

    return 'bg-[#0ABFBF]/10 text-[#0a8f8f] ring-[#0ABFBF]/20 dark:text-[#0ABFBF]';
}

/** Format a cell value for display. Empty/placeholder values read as an en-dash. */
export function formatCell(
    value: string | number | undefined,
    column: ReportColumn,
): string {
    if (value === undefined || value === null || value === '') {
        return '—';
    }

    if (column.type === 'number' && typeof value === 'number') {
        return value.toLocaleString();
    }

    return String(value);
}

/** Build the export URL for a report, carrying the active filters. */
export function exportUrl(
    key: string,
    applied: Record<string, string>,
): string {
    const params = new URLSearchParams();

    for (const [name, value] of Object.entries(applied)) {
        if (value !== '' && value !== 'all') {
            params.set(name, value);
        }
    }

    const query = params.toString();

    return `/reports/${key}/export${query ? `?${query}` : ''}`;
}

/** An icon for a report's section, used on the hub. */
export function groupIcon(group: string): LucideIcon {
    switch (group) {
        case 'Workforce':
            return Users;
        case 'Attendance':
            return CalendarCheck;
        case 'Leave':
            return ClipboardList;
        case 'Recruitment':
            return BarChart3;
        case 'System':
            return ScrollText;
        default:
            return BarChart3;
    }
}
