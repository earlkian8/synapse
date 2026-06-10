import { Download, Plus, RotateCcw, Search, X } from 'lucide-react';
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
import { TYPE_FILTERS } from '../constants';
import { roleRoutes } from '../routes';
import type { RolesFilters } from '../types';

type Props = {
    filters: RolesFilters;
    canCreate: boolean;
    onSearch: (value: string) => void;
    onType: (value: string) => void;
    onReset: () => void;
    onCreate: () => void;
};

export function RolesToolbar({
    filters,
    canCreate,
    onSearch,
    onType,
    onReset,
    onCreate,
}: Props) {
    const [term, setTerm] = useState(filters.search);
    const [syncedSearch, setSyncedSearch] = useState(filters.search);

    // Re-sync the field when the server filter changes (e.g. Reset).
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

    const hasActiveFilters = filters.search !== '' || filters.type !== 'all';

    const exportUrl = `${roleRoutes.export}${
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
                        placeholder="Search roles…"
                        className="pl-9"
                        aria-label="Search roles"
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

                <Select value={filters.type} onValueChange={onType}>
                    <SelectTrigger
                        className="w-[150px]"
                        aria-label="Filter by type"
                    >
                        <SelectValue placeholder="Type" />
                    </SelectTrigger>
                    <SelectContent>
                        {TYPE_FILTERS.map((option) => (
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
                <Button variant="outline" size="sm" asChild>
                    <a href={exportUrl}>
                        <Download className="size-4" />
                        Export
                    </a>
                </Button>
                {canCreate && (
                    <Button size="sm" onClick={onCreate}>
                        <Plus className="size-4" />
                        New role
                    </Button>
                )}
            </div>
        </div>
    );
}
