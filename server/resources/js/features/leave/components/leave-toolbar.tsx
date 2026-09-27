import { Plus } from 'lucide-react';
import {
    FilterSelect,
    ListToolbar,
    SearchInput,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
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
    const filtered =
        filters.search !== '' ||
        filters.type !== null ||
        filters.department !== null ||
        filters.status !== DEFAULT_FILTERS.status;

    return (
        <ListToolbar
            filtered={filtered}
            onReset={onReset}
            actions={
                canRequest && (
                    <Button size="sm" onClick={onFile}>
                        <Plus className="size-4" />
                        File leave
                    </Button>
                )
            }
        >
            <SearchInput
                value={filters.search}
                onSearch={onSearch}
                placeholder="Search by name or no.…"
                label="Search leave requests"
            />
            <FilterSelect
                label="Filter by status"
                value={filters.status}
                onChange={onStatus}
                options={STATUS_FILTERS}
                className="w-36"
            />
            <FilterSelect
                label="Filter by leave type"
                value={filters.type ? String(filters.type) : 'all'}
                onChange={(value) =>
                    onType(value === 'all' ? null : Number(value))
                }
                options={[
                    { value: 'all', label: 'All leave types' },
                    ...types.map((type) => ({
                        value: String(type.id),
                        label: type.name,
                    })),
                ]}
            />
            <FilterSelect
                label="Filter by department"
                value={filters.department ? String(filters.department) : 'all'}
                onChange={(value) =>
                    onDepartment(value === 'all' ? null : Number(value))
                }
                options={[
                    { value: 'all', label: 'All departments' },
                    ...departments.map((department) => ({
                        value: String(department.id),
                        label: department.name,
                    })),
                ]}
                className="w-44"
            />
        </ListToolbar>
    );
}
