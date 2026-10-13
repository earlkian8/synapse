import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

const WIDTHS = {
    sm: 'sm:max-w-md',
    md: 'sm:max-w-xl',
    lg: 'sm:max-w-2xl',
} as const;

/**
 * A centred dialog for one record or one form: a compact header, a body that
 * scrolls inside the dialog (never the page behind it), and an optional footer
 * pinned to the bottom.
 */
export function RecordDialog({
    open,
    onOpenChange,
    title,
    description,
    header,
    footer,
    size = 'lg',
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Read by screen readers; `header` is what is seen. */
    title: string;
    description: string;
    header?: ReactNode;
    footer?: ReactNode;
    size?: keyof typeof WIDTHS;
    children: ReactNode;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className={cn(
                    'flex max-h-[calc(100dvh-2rem)] flex-col gap-0 overflow-hidden p-0',
                    WIDTHS[size],
                )}
            >
                <DialogTitle
                    className={header ? 'sr-only' : 'px-5 pt-4 pr-12 text-base'}
                >
                    {title}
                </DialogTitle>
                <DialogDescription
                    className={
                        header
                            ? 'sr-only'
                            : 'border-b border-border px-5 pt-0.5 pr-12 pb-3.5 text-sm'
                    }
                >
                    {description}
                </DialogDescription>
                {header && (
                    <div className="border-b border-border bg-muted/30 px-5 py-4 pr-12">
                        {header}
                    </div>
                )}
                <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    {children}
                </div>
                {footer && (
                    <div className="flex flex-wrap justify-end gap-2 border-t border-border px-5 py-3">
                        {footer}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

/** The record's identity, on one line: a mark, the name, a line under it, and badges. */
export function RecordHeading({
    leading,
    title,
    subtitle,
    badges,
}: {
    leading?: ReactNode;
    title: ReactNode;
    subtitle?: ReactNode;
    badges?: ReactNode;
}) {
    return (
        <div className="flex min-w-0 items-center gap-3">
            {leading}
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <h2 className="truncate text-base leading-tight font-semibold">
                        {title}
                    </h2>
                    {badges}
                </div>
                {subtitle && (
                    <p className="mt-0.5 truncate text-sm text-muted-foreground">
                        {subtitle}
                    </p>
                )}
            </div>
        </div>
    );
}

/** A titled block inside a dialog. */
export function RecordSection({
    title,
    aside,
    children,
    className,
}: {
    title: string;
    aside?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('space-y-2', className)}>
            <div className="flex items-baseline justify-between gap-2">
                <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {title}
                </h3>
                {aside && (
                    <span className="text-xs text-muted-foreground">
                        {aside}
                    </span>
                )}
            </div>
            {children}
        </section>
    );
}

const FACT_COLUMNS = {
    2: 'grid-cols-2',
    3: 'grid-cols-2 sm:grid-cols-3',
    4: 'grid-cols-2 sm:grid-cols-4',
} as const;

/**
 * Facts packed into one bordered grid, label over value, so nothing floats at
 * the far edge of a wide row.
 */
export function FactGrid({
    columns = 4,
    children,
}: {
    columns?: keyof typeof FACT_COLUMNS;
    children: ReactNode;
}) {
    return (
        <dl
            className={cn(
                'grid gap-px overflow-hidden rounded-lg border border-sidebar-border/70 bg-sidebar-border/70 dark:border-sidebar-border dark:bg-sidebar-border',
                FACT_COLUMNS[columns],
            )}
        >
            {children}
        </dl>
    );
}

export function Fact({
    icon: Icon,
    label,
    value,
    tone,
    title,
    wide = false,
}: {
    icon?: LucideIcon;
    label: string;
    value?: ReactNode;
    tone?: 'positive' | 'warning' | 'negative';
    title?: string;
    /** Spans the whole row (a long value: an address, a browser). */
    wide?: boolean;
}) {
    const empty = value === null || value === undefined || value === '';

    return (
        <div
            className={cn('min-w-0 bg-card px-3 py-2', wide && 'col-span-full')}
        >
            <dt className="flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground">
                {Icon && <Icon className="size-3.5 shrink-0" />}
                {label}
            </dt>
            <dd
                title={title}
                className={cn(
                    'mt-0.5 text-sm font-medium',
                    wide ? 'break-words' : 'truncate',
                    empty && 'font-normal text-muted-foreground',
                    tone === 'positive' &&
                        'text-emerald-600 dark:text-emerald-400',
                    tone === 'warning' && 'text-amber-600 dark:text-amber-400',
                    tone === 'negative' && 'text-rose-600 dark:text-rose-400',
                )}
            >
                {empty ? '—' : value}
            </dd>
        </div>
    );
}

/** Rows of people or places, in one bordered list. */
export function RecordList({
    empty,
    children,
}: {
    empty?: string;
    children?: ReactNode;
}) {
    const hasRows = Array.isArray(children)
        ? children.length > 0
        : Boolean(children);

    if (!hasRows) {
        return (
            <p className="rounded-lg border border-dashed border-sidebar-border px-3 py-2.5 text-sm text-muted-foreground">
                {empty}
            </p>
        );
    }

    return (
        <ul className="divide-y divide-border overflow-hidden rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
            {children}
        </ul>
    );
}

/** A loading body shaped like a record: a heading, a fact grid, a list. */
export function RecordSkeleton() {
    return (
        <div className="space-y-4" aria-busy="true">
            <div className="h-5 w-48 animate-pulse rounded bg-muted" />
            <div className="h-28 animate-pulse rounded-lg bg-muted" />
            <div className="h-24 animate-pulse rounded-lg bg-muted" />
        </div>
    );
}
