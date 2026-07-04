import { Download, LayoutGrid, Search, Table2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { trainingRoutes } from '../routes';
import type { ProgramsView, ProgramStatus } from '../types';

const STATUS_OPTIONS: { value: ProgramStatus | 'all'; label: string }[] = [
    { value: 'all', label: 'All statuses' },
    { value: 'ongoing', label: 'Ongoing' },
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'completed', label: 'Completed' },
];

type Props = {
    search: string;
    status: ProgramStatus | 'all';
    view: ProgramsView;
    shown: number;
    total: number;
    canExport: boolean;
    onSearch: (value: string) => void;
    onStatus: (value: ProgramStatus | 'all') => void;
    onView: (value: ProgramsView) => void;
};

/**
 * The programs overview utility bar — client-side search, a status filter, a
 * table/grid layout toggle, a live result count and a CSV export.
 */
export function TrainingToolbar({
    search,
    status,
    view,
    shown,
    total,
    canExport,
    onSearch,
    onStatus,
    onView,
}: Props) {
    const filtering = search.trim() !== '' || status !== 'all';

    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex flex-1 flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-64">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(event) => onSearch(event.target.value)}
                        placeholder="Search programs…"
                        className="pl-9"
                        aria-label="Search programs"
                    />
                    {search.trim() !== '' && (
                        <button
                            type="button"
                            onClick={() => onSearch('')}
                            className="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                            aria-label="Clear search"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>

                <Select
                    value={status}
                    onValueChange={(value) =>
                        onStatus(value as ProgramStatus | 'all')
                    }
                >
                    <SelectTrigger
                        className="w-[150px]"
                        aria-label="Filter by status"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {STATUS_OPTIONS.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <span className="text-xs text-muted-foreground tabular-nums">
                    {filtering ? `${shown} of ${total}` : `${total}`} program
                    {total === 1 && !filtering ? '' : 's'}
                </span>
            </div>

            <div className="flex items-center gap-2">
                <ViewToggle view={view} onView={onView} />
                {canExport && (
                    <Button variant="outline" size="sm" asChild>
                        <a href={trainingRoutes.export}>
                            <Download className="size-4" />
                            Export
                        </a>
                    </Button>
                )}
            </div>
        </div>
    );
}

function ViewToggle({
    view,
    onView,
}: {
    view: ProgramsView;
    onView: (value: ProgramsView) => void;
}) {
    return (
        <div className="inline-flex items-center rounded-lg border border-sidebar-border/70 bg-card p-0.5 dark:border-sidebar-border">
            <ToggleButton
                active={view === 'table'}
                onClick={() => onView('table')}
                label="Table view"
            >
                <Table2 className="size-4" />
            </ToggleButton>
            <ToggleButton
                active={view === 'grid'}
                onClick={() => onView('grid')}
                label="Grid view"
            >
                <LayoutGrid className="size-4" />
            </ToggleButton>
        </div>
    );
}

function ToggleButton({
    active,
    onClick,
    label,
    children,
}: {
    active: boolean;
    onClick: () => void;
    label: string;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            aria-pressed={active}
            className={cn(
                'flex size-7 items-center justify-center rounded-md transition-colors',
                active
                    ? 'bg-[#0ABFBF]/15 text-[#0a8b91] dark:text-[#0ABFBF]'
                    : 'text-muted-foreground hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}
