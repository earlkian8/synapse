import { ArrowDown, ArrowUp, ChevronsUpDown, ScrollText } from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { ActivityFilters, ActivityLogEntry } from '../types';
import { ActivityEventBadge } from './activity-event-badge';
import { ActivityRowActions } from './activity-row-actions';
import { ActorAvatar } from './actor-cell';

type Props = {
    logs: ActivityLogEntry[];
    filters: ActivityFilters;
    selected: number[];
    onToggleSort: (column: string) => void;
    onToggleAll: (checked: boolean) => void;
    onToggleRow: (id: number, checked: boolean) => void;
    onView: (log: ActivityLogEntry) => void;
    onDelete: (log: ActivityLogEntry) => void;
};

export function ActivityTable({
    logs,
    filters,
    selected,
    onToggleSort,
    onToggleAll,
    onToggleRow,
    onView,
    onDelete,
}: Props) {
    const allSelected = logs.length > 0 && selected.length === logs.length;
    const someSelected = selected.length > 0 && !allSelected;

    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <Table>
                <TableHeader className="bg-muted/40">
                    <TableRow className="hover:bg-transparent">
                        <TableHead className="w-10 pl-4">
                            <Checkbox
                                checked={
                                    allSelected
                                        ? true
                                        : someSelected
                                          ? 'indeterminate'
                                          : false
                                }
                                onCheckedChange={(value) => onToggleAll(value === true)}
                                aria-label="Select all"
                            />
                        </TableHead>
                        <TableHead>Actor</TableHead>
                        <SortHeader column="event" filters={filters} onSort={onToggleSort}>
                            Event
                        </SortHeader>
                        <TableHead>Description</TableHead>
                        <TableHead>Target</TableHead>
                        <TableHead>IP address</TableHead>
                        <SortHeader column="created_at" filters={filters} onSort={onToggleSort}>
                            When
                        </SortHeader>
                        <TableHead className="w-10 pr-4" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {logs.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={8} className="py-16">
                                <div className="flex flex-col items-center justify-center gap-2 text-center">
                                    <span className="flex size-12 items-center justify-center rounded-full bg-muted">
                                        <ScrollText className="size-6 text-muted-foreground" />
                                    </span>
                                    <p className="text-sm font-medium">No activity yet</p>
                                    <p className="max-w-xs text-sm text-muted-foreground">
                                        Actions performed across the system will appear
                                        here. Try adjusting your search or filters.
                                    </p>
                                </div>
                            </TableCell>
                        </TableRow>
                    )}

                    {logs.map((log) => {
                        const isSelected = selected.includes(log.id);

                        return (
                            <TableRow
                                key={log.id}
                                data-state={isSelected ? 'selected' : undefined}
                            >
                                <TableCell className="pl-4">
                                    <Checkbox
                                        checked={isSelected}
                                        onCheckedChange={(value) =>
                                            onToggleRow(log.id, value === true)
                                        }
                                        aria-label={`Select log ${log.id}`}
                                    />
                                </TableCell>
                                <TableCell>
                                    <button
                                        type="button"
                                        onClick={() => onView(log)}
                                        className="flex items-center gap-3 text-left"
                                    >
                                        <ActorAvatar causer={log.causer} />
                                        <span className="min-w-0">
                                            <span className="block truncate font-medium">
                                                {log.causer?.full_name ?? 'System'}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {log.causer?.email ?? 'Automated action'}
                                            </span>
                                        </span>
                                    </button>
                                </TableCell>
                                <TableCell>
                                    <ActivityEventBadge event={log.event} />
                                </TableCell>
                                <TableCell className="max-w-xs">
                                    <span className="block truncate text-sm">
                                        {log.description}
                                    </span>
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {log.subject_label ??
                                        (log.subject_type
                                            ? `${log.subject_type} #${log.subject_id}`
                                            : '—')}
                                </TableCell>
                                <TableCell>
                                    {log.ip_address ? (
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {log.ip_address}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">—</span>
                                    )}
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    <span title={log.created_display ?? undefined}>
                                        {log.created_human ?? '—'}
                                    </span>
                                </TableCell>
                                <TableCell className="pr-4 text-right">
                                    <ActivityRowActions
                                        log={log}
                                        onView={onView}
                                        onDelete={onDelete}
                                    />
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

function SortHeader({
    column,
    filters,
    onSort,
    children,
}: {
    column: string;
    filters: ActivityFilters;
    onSort: (column: string) => void;
    children: React.ReactNode;
}) {
    const active = filters.sort === column;

    return (
        <TableHead>
            <button
                type="button"
                onClick={() => onSort(column)}
                className={cn(
                    'inline-flex items-center gap-1 transition-colors hover:text-foreground',
                    active && 'text-foreground',
                )}
            >
                {children}
                {active ? (
                    filters.direction === 'asc' ? (
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
