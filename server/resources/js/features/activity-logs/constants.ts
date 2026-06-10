export const EVENT_FILTERS = [
    { value: 'all', label: 'All events' },
    { value: 'created', label: 'Created' },
    { value: 'updated', label: 'Updated' },
    { value: 'activated', label: 'Activated' },
    { value: 'deactivated', label: 'Deactivated' },
    { value: 'password_reset', label: 'Password reset' },
    { value: 'archived', label: 'Archived' },
    { value: 'restored', label: 'Restored' },
    { value: 'deleted', label: 'Deleted' },
] as const;

export const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100] as const;

export const DEFAULT_FILTERS = {
    search: '',
    event: 'all',
    sort: 'created_at',
    direction: 'desc',
    per_page: 10,
} as const;

type EventStyle = { label: string; text: string; bg: string; dot: string };

/**
 * Visual metadata per event. Unknown events fall back to `default`.
 */
export const EVENT_META: Record<string, EventStyle> = {
    created: {
        label: 'Created',
        text: 'text-emerald-700 dark:text-emerald-400',
        bg: 'bg-emerald-50 dark:bg-emerald-500/10 border-emerald-200 dark:border-emerald-500/20',
        dot: 'bg-emerald-500',
    },
    updated: {
        label: 'Updated',
        text: 'text-[#0ABFBF] dark:text-[#0ABFBF]',
        bg: 'bg-[#0ABFBF]/10 border-[#0ABFBF]/20',
        dot: 'bg-[#0ABFBF]',
    },
    activated: {
        label: 'Activated',
        text: 'text-emerald-700 dark:text-emerald-400',
        bg: 'bg-emerald-50 dark:bg-emerald-500/10 border-emerald-200 dark:border-emerald-500/20',
        dot: 'bg-emerald-500',
    },
    deactivated: {
        label: 'Deactivated',
        text: 'text-amber-700 dark:text-amber-400',
        bg: 'bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20',
        dot: 'bg-amber-500',
    },
    password_reset: {
        label: 'Password reset',
        text: 'text-violet-700 dark:text-violet-400',
        bg: 'bg-violet-50 dark:bg-violet-500/10 border-violet-200 dark:border-violet-500/20',
        dot: 'bg-violet-500',
    },
    archived: {
        label: 'Archived',
        text: 'text-amber-700 dark:text-amber-400',
        bg: 'bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20',
        dot: 'bg-amber-500',
    },
    restored: {
        label: 'Restored',
        text: 'text-indigo-700 dark:text-indigo-400',
        bg: 'bg-indigo-50 dark:bg-indigo-500/10 border-indigo-200 dark:border-indigo-500/20',
        dot: 'bg-indigo-500',
    },
    deleted: {
        label: 'Deleted',
        text: 'text-rose-700 dark:text-rose-400',
        bg: 'bg-rose-50 dark:bg-rose-500/10 border-rose-200 dark:border-rose-500/20',
        dot: 'bg-rose-500',
    },
    default: {
        label: 'Event',
        text: 'text-neutral-600 dark:text-neutral-400',
        bg: 'bg-neutral-100 dark:bg-neutral-500/10 border-neutral-200 dark:border-neutral-500/20',
        dot: 'bg-neutral-400',
    },
};
