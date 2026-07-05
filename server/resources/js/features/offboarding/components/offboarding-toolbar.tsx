import {
    Download,
    LayoutGrid,
    Plus,
    RotateCcw,
    Search,
    Table2,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
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
import { DEFAULT_FILTERS, STATUS_FILTERS, TYPE_OPTIONS } from '../constants';
import type { CasesView } from '../hooks/use-cases-view';
import { offboardingRoutes } from '../routes';
import type { DepartmentRef, OffboardingFilters } from '../types';

type Props = {
    filters: OffboardingFilters;
    departments: DepartmentRef[];
    canManage: boolean;
    view: CasesView;
    onSearch: (value: string) => void;
    onStatus: (value: string) => void;
    onType: (value: string | null) => void;
    onDepartment: (value: number | null) => void;
    onReset: () => void;
    onStart: () => void;
    onView: (value: CasesView) => void;
};

/** The export URL carrying the board's current filters, so CSV = what you see. */
function exportUrl(filters: OffboardingFilters): string {
    const params = new URLSearchParams();

    if (filters.search) {
        params.set('search', filters.search);
    }

    if (filters.status && filters.status !== DEFAULT_FILTERS.status) {
        params.set('status', filters.status);
    }

    if (filters.type) {
        params.set('type', filters.type);
    }

    if (filters.department) {
        params.set('department', String(filters.department));
    }

    const query = params.toString();

    return query
        ? `${offboardingRoutes.export}?${query}`
        : offboardingRoutes.export;
}

export function OffboardingToolbar({
    filters,
    departments,
    canManage,
    view,
    onSearch,
    onStatus,
    onType,
    onDepartment,
    onReset,
    onStart,
    onView,
}: Props) {
    const [term, setTerm] = useState(filters.search);
    const [syncedSearch, setSyncedSearch] = useState(filters.search);

    if (filters.search !== syncedSearch) {
        setSyncedSearch(filters.search);
        setTerm(filters.search);
    }

    useEffect(() => {
        const handle = window.setTimeout(() => {
            if (term !== filters.search) {
                onSearch(term);
            }
        }, 350);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    const hasActiveFilters =
        filters.search !== '' ||
        filters.status !== DEFAULT_FILTERS.status ||
        filters.type !== null ||
        filters.department !== null;

    return (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-1 flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-64">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        placeholder="Search by name or no.…"
                        className="pl-9"
                        aria-label="Search offboarding"
                    />
                    {term && (
                        <button
                            type="button"
                            onClick={() => setTerm('')}
                            className="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                            aria-label="Clear search"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>

                <Select
                    value={filters.type ?? 'all'}
                    onValueChange={(value) =>
                        onType(value === 'all' ? null : value)
                    }
                >
                    <SelectTrigger
                        className="w-[150px]"
                        aria-label="Filter by exit type"
                    >
                        <SelectValue placeholder="Type" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All types</SelectItem>
                        {TYPE_OPTIONS.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select
                    value={
                        filters.department ? String(filters.department) : 'all'
                    }
                    onValueChange={(value) =>
                        onDepartment(value === 'all' ? null : Number(value))
                    }
                >
                    <SelectTrigger
                        className="w-[160px]"
                        aria-label="Filter by department"
                    >
                        <SelectValue placeholder="Department" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All departments</SelectItem>
                        {departments.map((department) => (
                            <SelectItem
                                key={department.id}
                                value={String(department.id)}
                            >
                                {department.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select value={filters.status} onValueChange={onStatus}>
                    <SelectTrigger
                        className="w-[150px]"
                        aria-label="Filter by status"
                    >
                        <SelectValue placeholder="Status" />
                    </SelectTrigger>
                    <SelectContent>
                        {STATUS_FILTERS.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {hasActiveFilters && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onReset}
                        className="text-muted-foreground"
                    >
                        <RotateCcw className="size-4" />
                        Reset
                    </Button>
                )}
            </div>

            <div className="flex items-center gap-2">
                <ViewToggle view={view} onView={onView} />
                <Button variant="outline" size="sm" asChild>
                    <a href={exportUrl(filters)}>
                        <Download className="size-4" />
                        Export
                    </a>
                </Button>
                {canManage && (
                    <Button size="sm" onClick={onStart}>
                        <Plus className="size-4" />
                        Start offboarding
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
    view: CasesView;
    onView: (value: CasesView) => void;
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
