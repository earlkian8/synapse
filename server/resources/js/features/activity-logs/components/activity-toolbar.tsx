import { Download, RotateCcw, Search, Trash2, X } from 'lucide-react';
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
import { EVENT_FILTERS } from '../constants';
import { activityLogRoutes } from '../routes';
import type { ActivityFilters } from '../types';

type Props = {
    filters: ActivityFilters;
    canDelete: boolean;
    canExport: boolean;
    onSearch: (value: string) => void;
    onEvent: (value: string) => void;
    onReset: () => void;
    onClear: () => void;
};

export function ActivityToolbar({
    filters,
    canDelete,
    canExport,
    onSearch,
    onEvent,
    onReset,
    onClear,
}: Props) {
    const [term, setTerm] = useState(filters.search);
    const [syncedSearch, setSyncedSearch] = useState(filters.search);

    // Re-sync the field when the server filter changes (e.g. Reset) — render-phase
    // adjustment is preferred over an effect for deriving state from props.
    if (filters.search !== syncedSearch) {
        setSyncedSearch(filters.search);
        setTerm(filters.search);
    }

    // Debounce the search input before hitting the server.
    useEffect(() => {
        const handle = window.setTimeout(() => {
            if (term !== filters.search) {
                onSearch(term);
            }
        }, 350);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    const hasActiveFilters = filters.search !== '' || filters.event !== 'all';

    const exportUrl = `${activityLogRoutes.export}${
        typeof window !== 'undefined' ? window.location.search : ''
    }`;

    return (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-1 flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-72">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        placeholder="Search description, actor or IP…"
                        className="pl-9"
                        aria-label="Search activity logs"
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

                <Select value={filters.event} onValueChange={onEvent}>
                    <SelectTrigger className="w-[160px]" aria-label="Filter by event">
                        <SelectValue placeholder="Event" />
                    </SelectTrigger>
                    <SelectContent>
                        {EVENT_FILTERS.map((option) => (
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
                {canExport && (
                    <Button variant="outline" size="sm" asChild>
                        <a href={exportUrl}>
                            <Download className="size-4" />
                            Export
                        </a>
                    </Button>
                )}
                {canDelete && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onClear}
                        className="text-destructive hover:text-destructive"
                    >
                        <Trash2 className="size-4" />
                        Clear logs
                    </Button>
                )}
            </div>
        </div>
    );
}
