import {
    CircleSlash,
    Gauge,
    MessageCircleQuestion,
    PanelsTopLeft,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReportCategory, ReportImpact, ReportStatus } from './types';

/**
 * The reporter's choices, in the order the dialog offers them. The values and
 * labels mirror `ProblemReport::CATEGORIES` and `::IMPACTS` on the server; the
 * one-line hints are the dialog's own.
 */
export const CATEGORIES: {
    value: ReportCategory;
    label: string;
    hint: string;
    icon: LucideIcon;
}[] = [
    {
        value: 'not_working',
        label: 'Something isn’t working',
        hint: 'An error, or a button that does nothing',
        icon: CircleSlash,
    },
    {
        value: 'looks_wrong',
        label: 'Something looks wrong',
        hint: 'Text cut off, overlapping, unreadable',
        icon: PanelsTopLeft,
    },
    {
        value: 'slow',
        label: 'It’s slow or won’t load',
        hint: 'A page that hangs or takes too long',
        icon: Gauge,
    },
    {
        value: 'other',
        label: 'Something else',
        hint: 'Anything that got in your way',
        icon: MessageCircleQuestion,
    },
];

export const IMPACTS: { value: ReportImpact; label: string }[] = [
    { value: 'blocked', label: 'I can’t get my work done' },
    { value: 'workaround', label: 'I can work around it' },
    { value: 'minor', label: 'It’s minor' },
];

/** The three steps a report moves through, as the reporter is shown them. */
export const STEPS: { status: ReportStatus; label: string }[] = [
    { status: 'new', label: 'Received' },
    { status: 'investigating', label: 'Being looked into' },
    { status: 'resolved', label: 'Resolved' },
];

/** One tone per status: the pill's border, fill and text, and its dot. */
export const STATUS_TONES: Record<ReportStatus, { pill: string; dot: string }> =
    {
        new: {
            pill: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300',
            dot: 'bg-sky-500',
        },
        investigating: {
            pill: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
            dot: 'bg-amber-500',
        },
        resolved: {
            pill: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
            dot: 'bg-emerald-500',
        },
        closed: {
            pill: 'border-border bg-muted text-muted-foreground',
            dot: 'bg-neutral-400',
        },
    };

/** What the server accepts (`Screenshots::MAX`, `::MAX_KB`, the mime list). */
export const SCREENSHOTS = {
    max: 3,
    maxBytes: 8 * 1024 * 1024,
    types: ['image/png', 'image/jpeg', 'image/webp'],
} as const;
