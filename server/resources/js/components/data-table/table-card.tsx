import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ChevronsUpDown,
    MoreHorizontal,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { forwardRef } from 'react';
import type {
    ComponentProps,
    KeyboardEvent,
    MouseEvent,
    ReactNode,
} from 'react';
import { Table, TableCell, TableHead, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';

/**
 * The card a table sits in. With a `title`, a slim header strip carries it, a
 * count, and the table's own actions (Export, Invite…).
 */
export function TableCard({
    title,
    count,
    description,
    actions,
    children,
    className,
}: {
    title?: ReactNode;
    count?: number;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border',
                className,
            )}
        >
            {(title || actions) && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-2">
                    <div className="min-w-0">
                        {title && (
                            <p className="text-sm font-semibold">
                                {title}
                                {count !== undefined && (
                                    <span className="ml-1 font-normal text-muted-foreground tabular-nums">
                                        ({count.toLocaleString()})
                                    </span>
                                )}
                            </p>
                        )}
                        {description && (
                            <p className="text-xs text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                    {actions && (
                        <div className="flex flex-wrap items-center gap-2">
                            {actions}
                        </div>
                    )}
                </div>
            )}
            {children}
        </div>
    );
}

/**
 * A table at the modules' shared density: a tinted 36px header, 8px-tall cell
 * padding, and 16px at either edge — set once here, so every list reads alike.
 */
export function DataTable({
    className,
    ...props
}: ComponentProps<typeof Table>) {
    return (
        <Table
            className={cn(
                '[&_thead]:bg-muted/40 [&_thead_tr]:hover:bg-transparent',
                '[&_td]:py-2 [&_th]:h-9',
                '[&_td:first-child]:pl-4 [&_td:last-child]:pr-4 [&_th:first-child]:pl-4 [&_th:last-child]:pr-4',
                className,
            )}
            {...props}
        />
    );
}

/** A column header that sorts: first click ascending, then descending. */
export function SortableHead({
    children,
    active,
    direction,
    onSort,
    className,
    align = 'left',
}: {
    children: ReactNode;
    active: boolean;
    direction: 'asc' | 'desc';
    onSort: () => void;
    className?: string;
    align?: 'left' | 'right';
}) {
    return (
        <TableHead
            className={cn(align === 'right' && 'text-right', className)}
            aria-sort={
                active
                    ? direction === 'asc'
                        ? 'ascending'
                        : 'descending'
                    : undefined
            }
        >
            <button
                type="button"
                onClick={onSort}
                className={cn(
                    // A button resets text-transform; keep the header's own.
                    'inline-flex items-center gap-1 tracking-wide uppercase transition-colors hover:text-foreground',
                    align === 'right' && 'flex-row-reverse',
                    active && 'text-foreground',
                )}
            >
                {children}
                {active ? (
                    direction === 'asc' ? (
                        <ArrowUp className="size-3.5" />
                    ) : (
                        <ArrowDown className="size-3.5" />
                    )
                ) : (
                    <ChevronsUpDown className="size-3.5 opacity-40" />
                )}
            </button>
        </TableHead>
    );
}

/** The one row an empty table shows: what is missing, and what to do. */
export function EmptyTableRow({
    colSpan,
    icon: Icon,
    title,
    description,
    action,
}: {
    colSpan: number;
    icon: LucideIcon;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
}) {
    return (
        <TableRow className="hover:bg-transparent">
            <TableCell colSpan={colSpan} className="py-10 whitespace-normal">
                <div className="flex flex-col items-center justify-center gap-1.5 text-center">
                    <span className="flex size-10 items-center justify-center rounded-full bg-muted">
                        <Icon className="size-5 text-muted-foreground" />
                    </span>
                    <p className="text-sm font-medium">{title}</p>
                    {description && (
                        <p className="max-w-sm text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                    {action && <div className="mt-1.5">{action}</div>}
                </div>
            </TableCell>
        </TableRow>
    );
}

const INTERACTIVE =
    'a, button, input, textarea, select, label, [role="menuitem"], [role="option"], [role="checkbox"], [role="combobox"], [role="radio"]';

/**
 * Props that make a whole row open something — a page (an href) or a dialog (a
 * callback) — while its links, buttons and menus keep their own clicks. The row
 * is focusable and opens on Enter, so the keyboard gets it too.
 */
export function rowOpens(
    target: string | (() => void),
): Pick<
    ComponentProps<'tr'>,
    'onClick' | 'onKeyDown' | 'tabIndex' | 'className'
> {
    const open = () =>
        typeof target === 'string' ? router.visit(target) : target();

    return {
        tabIndex: 0,
        className:
            'cursor-pointer focus-visible:bg-muted/50 focus-visible:outline-none',
        onClick: (event: MouseEvent<HTMLTableRowElement>) => {
            if (!(event.target as HTMLElement).closest(INTERACTIVE)) {
                open();
            }
        },
        onKeyDown: (event: KeyboardEvent<HTMLTableRowElement>) => {
            if (event.key === 'Enter' && event.target === event.currentTarget) {
                event.preventDefault();
                open();
            }
        },
    };
}

/** The "⋯" button a row's menu opens from. */
export const RowMenuTrigger = forwardRef<
    HTMLButtonElement,
    ComponentProps<'button'> & { label: string }
>(function RowMenuTrigger({ label, className, ...props }, ref) {
    return (
        <button
            ref={ref}
            type="button"
            aria-label={label}
            className={cn(
                'ml-auto rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted',
                className,
            )}
            {...props}
        >
            <MoreHorizontal className="size-4" />
        </button>
    );
});
