import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** The ERP's status badge: a tinted pill with a dot, one tone per meaning. */
const TONES = {
    emerald: {
        dot: 'bg-emerald-500',
        text: 'text-emerald-700 dark:text-emerald-400',
        bg: 'bg-emerald-50 dark:bg-emerald-500/10 border-emerald-200 dark:border-emerald-500/20',
    },
    amber: {
        dot: 'bg-amber-500',
        text: 'text-amber-700 dark:text-amber-400',
        bg: 'bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20',
    },
    rose: {
        dot: 'bg-rose-500',
        text: 'text-rose-700 dark:text-rose-400',
        bg: 'bg-rose-50 dark:bg-rose-500/10 border-rose-200 dark:border-rose-500/20',
    },
    sky: {
        dot: 'bg-sky-500',
        text: 'text-sky-700 dark:text-sky-400',
        bg: 'bg-sky-50 dark:bg-sky-500/10 border-sky-200 dark:border-sky-500/20',
    },
    teal: {
        dot: 'bg-[#0ABFBF]',
        text: 'text-[#08767c] dark:text-[#3dd6d6]',
        bg: 'bg-[#0ABFBF]/8 border-[#0ABFBF]/25',
    },
    neutral: {
        dot: 'bg-neutral-400',
        text: 'text-neutral-600 dark:text-neutral-400',
        bg: 'bg-neutral-100 dark:bg-neutral-500/10 border-neutral-200 dark:border-neutral-500/20',
    },
} as const;

export type Tone = keyof typeof TONES;

export function StatusPill({
    tone,
    children,
    className,
}: {
    tone: Tone;
    children: ReactNode;
    className?: string;
}) {
    const style = TONES[tone];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                style.bg,
                style.text,
                className,
            )}
        >
            <span className={cn('size-1.5 rounded-full', style.dot)} />
            {children}
        </span>
    );
}
