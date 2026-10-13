import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** A titled group in a detail sheet, as the ERP's user sheet draws it. */
export function DetailGroup({
    title,
    children,
    action,
}: {
    title: string;
    children: ReactNode;
    action?: ReactNode;
}) {
    return (
        <section>
            <div className="mb-2 flex items-center justify-between gap-2">
                <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {title}
                </h3>
                {action}
            </div>
            <div className="space-y-1">{children}</div>
        </section>
    );
}

export function DetailRow({
    icon: Icon,
    label,
    value,
    tone,
    title,
}: {
    icon: LucideIcon;
    label: string;
    value?: ReactNode;
    tone?: 'positive' | 'warning';
    title?: string;
}) {
    return (
        <div className="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-muted/40">
            <span className="flex shrink-0 items-center gap-2 text-sm text-muted-foreground">
                <Icon className="size-4" />
                {label}
            </span>
            <span
                title={title}
                className={cn(
                    'min-w-0 truncate text-right text-sm font-medium',
                    tone === 'positive' &&
                        'text-emerald-600 dark:text-emerald-400',
                    tone === 'warning' && 'text-amber-600 dark:text-amber-400',
                )}
            >
                {value === null || value === undefined || value === ''
                    ? '—'
                    : value}
            </span>
        </div>
    );
}
