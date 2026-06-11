import { Link } from '@inertiajs/react';
import { ListChecks, Plus, RotateCcw, Search, X } from 'lucide-react';
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
import { DEFAULT_FILTERS, STATUS_FILTERS } from '../constants';
import { onboardingRoutes } from '../routes';
import type { DepartmentRef, OnboardingFilters } from '../types';

type Props = {
    filters: OnboardingFilters;
    departments: DepartmentRef[];
    canManage: boolean;
    canManagePrograms: boolean;
    onSearch: (value: string) => void;
    onStatus: (value: string) => void;
    onDepartment: (value: number | null) => void;
    onReset: () => void;
    onStart: () => void;
};

export function OnboardingToolbar({
    filters,
    departments,
    canManage,
    canManagePrograms,
    onSearch,
    onStatus,
    onDepartment,
    onReset,
    onStart,
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
                        aria-label="Search onboarding"
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
                {canManagePrograms && (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={onboardingRoutes.programs}>
                            <ListChecks className="size-4" />
                            Programs
                        </Link>
                    </Button>
                )}
                {canManage && (
                    <Button size="sm" onClick={onStart}>
                        <Plus className="size-4" />
                        Start onboarding
                    </Button>
                )}
            </div>
        </div>
    );
}
