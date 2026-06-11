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
import { STATUS_FILTERS } from '../constants';
import { recruitmentRoutes } from '../routes';
import type { DepartmentRef, PostingsFilters } from '../types';

type Props = {
    filters: PostingsFilters;
    departments: DepartmentRef[];
    canCreate: boolean;
    canExport: boolean;
    onSearch: (value: string) => void;
    onStatus: (value: string) => void;
    onDepartment: (value: number | null) => void;
    onReset: () => void;
    onCreate: () => void;
};

export function PostingsToolbar({
    filters,
    departments,
    canCreate,
    canExport,
    onSearch,
    onStatus,
    onDepartment,
    onReset,
    onCreate,
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
        filters.status !== 'all' ||
        filters.department !== null;

    const exportUrl = `${recruitmentRoutes.export}${
        typeof window !== 'undefined' ? window.location.search : ''
    }`;

    return (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-1 flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-64">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        placeholder="Search postings…"
                        className="pl-9"
                        aria-label="Search postings"
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
                        className="w-[140px]"
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
                {canExport && (
                    <Button variant="outline" size="sm" asChild>
                        <a href={exportUrl}>
                            <Download className="size-4" />
                            Export
                        </a>
                    </Button>
                )}
                {canCreate && (
                    <Button size="sm" onClick={onCreate}>
                        <Plus className="size-4" />
                        New posting
                    </Button>
                )}
            </div>
        </div>
    );
}
