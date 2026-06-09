import { cn } from '@/lib/utils';
import type { UserStatus } from '../types';

const STATUS_STYLES: Record<UserStatus, { dot: string; text: string; bg: string }> = {
    active: {
        dot: 'bg-emerald-500',
        text: 'text-emerald-700 dark:text-emerald-400',
        bg: 'bg-emerald-50 dark:bg-emerald-500/10 border-emerald-200 dark:border-emerald-500/20',
    },
    inactive: {
        dot: 'bg-amber-500',
        text: 'text-amber-700 dark:text-amber-400',
        bg: 'bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20',
    },
    archived: {
        dot: 'bg-neutral-400',
        text: 'text-neutral-600 dark:text-neutral-400',
        bg: 'bg-neutral-100 dark:bg-neutral-500/10 border-neutral-200 dark:border-neutral-500/20',
    },
};

const LABELS: Record<UserStatus, string> = {
    active: 'Active',
    inactive: 'Inactive',
    archived: 'Archived',
};

export function UserStatusBadge({ status }: { status: UserStatus }) {
    const style = STATUS_STYLES[status];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium',
                style.bg,
                style.text,
            )}
        >
            <span className={cn('size-1.5 rounded-full', style.dot)} />
            {LABELS[status]}
        </span>
    );
}
