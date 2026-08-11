import { Plus, RotateCcw, Search, X } from 'lucide-react';
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
import { DEFAULT_FILTERS, STATUS_FILTERS } from '../constants';
import type { DepartmentRef, LeaveFilters, LeaveTypeRef } from '../types';

type Props = {
    filters: LeaveFilters;
    types: LeaveTypeRef[];
    departments: DepartmentRef[];
    canRequest: boolean;
    onSearch: (value: string) => void;
    onStatus: (value: string) => void;
    onType: (value: number | null) => void;
    onDepartment: (value: number | null) => void;
    onReset: () => void;
    onFile: () => void;
};

export function LeaveToolbar({
    filters,
    types,
    departments,
    canRequest,
    onSearch,
    onStatus,
    onType,
    onDepartment,
    onReset,
    onFile,
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

    const hasSideFilters =
        filters.search !== '' ||
        filters.type !== null ||
        filters.department !== null ||
        filters.status !== DEFAULT_FILTERS.status;

    return (
        <div className="flex flex-col gap-3">
            {/* Status tabs */}
            <div className="-mb-px flex items-center gap-1 overflow-x-auto border-b border-border">
                {STATUS_FILTERS.map((tab) => {
                    const active = filters.status === tab.value;

                    return (
                        <button
                            key={tab.value}
                            type="button"
                            onClick={() => onStatus(tab.value)}
                            className={cn(
                                'relative border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                active
                                    ? 'border-[#0ABFBF] text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {tab.label}
                        </button>
                    );
                })}
            </div>

            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex flex-1 flex-wrap items-center gap-2">
                    <div className="relative w-full sm:w-64">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                            placeholder="Search by name or no.…"
                            className="pl-9"
                            aria-label="Search leave requests"
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
                        value={filters.type ? String(filters.type) : 'all'}
                        onValueChange={(value) =>
                            onType(value === 'all' ? null : Number(value))
                        }
                    >
                        <SelectTrigger
                            className="w-[150px]"
                            aria-label="Filter by leave type"
                        >
                            <SelectValue placeholder="Leave type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            {types.map((type) => (
                                <SelectItem
                                    key={type.id}
                                    value={String(type.id)}
                                >
                                    {type.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={
                            filters.department
                                ? String(filters.department)
                                : 'all'
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

                    {hasSideFilters && (
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

                {canRequest && (
                    <Button size="sm" onClick={onFile}>
                        <Plus className="size-4" />
                        File leave
                    </Button>
                )}
            </div>
        </div>
    );
}
