import { Download, Plus } from 'lucide-react';
import {
    FilterSelect,
    ListToolbar,
    SearchInput,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { STATUS_FILTERS, TYPE_FILTERS } from '../constants';
import { employeeRoutes } from '../routes';
import type { DepartmentRef, EmployeesFilters } from '../types';

type Props = {
    filters: EmployeesFilters;
    departments: DepartmentRef[];
    canCreate: boolean;
    canExport: boolean;
    onSearch: (value: string) => void;
    onStatus: (value: string) => void;
    onType: (value: string) => void;
    onDepartment: (value: number | null) => void;
    onReset: () => void;
    onCreate: () => void;
};

export function EmployeesToolbar({
    filters,
    departments,
    canCreate,
    canExport,
    onSearch,
    onStatus,
    onType,
    onDepartment,
    onReset,
    onCreate,
}: Props) {
    const filtered =
        filters.search !== '' ||
        filters.status !== 'all' ||
        filters.type !== 'all' ||
        filters.department !== null;

    const exportUrl = `${employeeRoutes.export}${
        typeof window !== 'undefined' ? window.location.search : ''
    }`;

    return (
        <ListToolbar
            filtered={filtered}
            onReset={onReset}
            actions={
                <>
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
                            New employee
                        </Button>
                    )}
                </>
            }
        >
            <SearchInput
                value={filters.search}
                onSearch={onSearch}
                placeholder="Search name, no., email…"
                label="Search employees"
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
            <FilterSelect
                label="Filter by status"
                value={filters.status}
                onChange={onStatus}
                options={STATUS_FILTERS}
                className="w-36"
            />
            <FilterSelect
                label="Filter by type"
                value={filters.type}
                onChange={onType}
                options={TYPE_FILTERS}
                className="w-36"
            />
        </ListToolbar>
    );
}
