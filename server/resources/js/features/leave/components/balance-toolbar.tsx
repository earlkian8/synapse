import { Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { DepartmentRef } from '../types';

type Props = {
    search: string;
    year: number;
    years: number[];
    department: number | null;
    departments: DepartmentRef[];
    onSearch: (value: string) => void;
    onYear: (value: number) => void;
    onDepartment: (value: number | null) => void;
};

export function BalanceToolbar({
    search,
    year,
    years,
    department,
    departments,
    onSearch,
    onYear,
    onDepartment,
}: Props) {
    const [term, setTerm] = useState(search);
    const [synced, setSynced] = useState(search);

    if (search !== synced) {
        setSynced(search);
        setTerm(search);
    }

    useEffect(() => {
        const handle = window.setTimeout(() => {
            if (term !== search) {
                onSearch(term);
            }
        }, 350);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div className="relative w-full sm:w-64">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    placeholder="Search employees…"
                    className="pl-9"
                    aria-label="Search employees"
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
                value={department ? String(department) : 'all'}
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
                    {departments.map((dept) => (
                        <SelectItem key={dept.id} value={String(dept.id)}>
                            {dept.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Select
                value={String(year)}
                onValueChange={(value) => onYear(Number(value))}
            >
                <SelectTrigger className="w-[110px]" aria-label="Select year">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {years.map((y) => (
                        <SelectItem key={y} value={String(y)}>
                            {y}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
