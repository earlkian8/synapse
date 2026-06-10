import {
    BellRing,
    CircleAlert,
    CircleCheck,
    Info,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { NotificationLevel } from './types';

type LevelStyle = {
    icon: LucideIcon;
    /** Tailwind classes for the icon chip background + foreground. */
    chip: string;
    /** Accent dot / ring colour. */
    accent: string;
};

export const LEVEL_STYLES: Record<NotificationLevel, LevelStyle> = {
    info: {
        icon: Info,
        chip: 'bg-[#0ABFBF]/12 text-[#0a8a8a] dark:text-[#0ABFBF]',
        accent: 'bg-[#0ABFBF]',
    },
    success: {
        icon: CircleCheck,
        chip: 'bg-emerald-500/12 text-emerald-600 dark:text-emerald-400',
        accent: 'bg-emerald-500',
    },
    warning: {
        icon: TriangleAlert,
        chip: 'bg-amber-500/12 text-amber-600 dark:text-amber-400',
        accent: 'bg-amber-500',
    },
    error: {
        icon: CircleAlert,
        chip: 'bg-rose-500/12 text-rose-600 dark:text-rose-400',
        accent: 'bg-rose-500',
    },
};

export const DEFAULT_LEVEL_STYLE = LEVEL_STYLES.info;

export const FALLBACK_ICON = BellRing;

export const LEVEL_OPTIONS: { value: NotificationLevel; label: string }[] = [
    { value: 'info', label: 'Info' },
    { value: 'success', label: 'Success' },
    { value: 'warning', label: 'Warning' },
    { value: 'error', label: 'Critical' },
];
