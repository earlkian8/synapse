import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** The tile accents, one per meaning, used the same way on every page. */
export const TILE_ACCENTS = {
    teal: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    emerald: 'text-emerald-600 bg-emerald-500/10 dark:text-emerald-400',
    amber: 'text-amber-600 bg-amber-500/10 dark:text-amber-400',
    rose: 'text-rose-600 bg-rose-500/10 dark:text-rose-400',
    sky: 'text-sky-600 bg-sky-500/10 dark:text-sky-400',
    violet: 'text-violet-600 bg-violet-500/10 dark:text-violet-400',
    indigo: 'text-indigo-600 bg-indigo-500/10 dark:text-indigo-400',
    slate: 'text-slate-600 bg-slate-500/10 dark:text-slate-300',
} as const;

export type StatTile = {
    key: string;
    label: string;
    value: ReactNode;
    icon: LucideIcon;
    accent: keyof typeof TILE_ACCENTS;
    /** A few words after the value, e.g. "of 42". */
    hint?: ReactNode;
    /** Colour the value itself (a count that should draw the eye). */
    valueClassName?: string;
};

const COLUMNS: Record<number, string> = {
    2: 'lg:grid-cols-2',
    3: 'lg:grid-cols-3',
    4: 'lg:grid-cols-4',
    5: 'lg:grid-cols-5',
    6: 'lg:grid-cols-6',
};

/**
 * A page's headline counts as compact tiles — icon, label and value on one line
 * — so the table below starts high on the page.
 */
export function StatTiles({
    tiles,
    className,
}: {
    tiles: StatTile[];
    className?: string;
}) {
    return (
        <div
            className={cn(
                'grid grid-cols-2 gap-2.5 sm:grid-cols-3',
                COLUMNS[Math.min(6, Math.max(2, tiles.length))],
                className,
            )}
        >
            {tiles.map(
                ({
                    key,
                    label,
                    value,
                    icon: Icon,
                    accent,
                    hint,
                    valueClassName,
                }) => (
                    <div
                        key={key}
                        className="flex min-w-0 items-center gap-3 rounded-xl border border-sidebar-border/70 bg-card px-3.5 py-2.5 shadow-sm dark:border-sidebar-border"
                    >
                        <span
                            className={cn(
                                'flex size-8 shrink-0 items-center justify-center rounded-lg',
                                TILE_ACCENTS[accent],
                            )}
                        >
                            <Icon className="size-4" />
                        </span>
                        <div className="min-w-0">
                            <p className="truncate text-xs font-medium text-muted-foreground">
                                {label}
                            </p>
                            <p className="truncate text-lg leading-tight font-semibold tracking-tight">
                                <span className={valueClassName}>{value}</span>
                                {hint && (
                                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                                        {hint}
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>
                ),
            )}
        </div>
    );
}
