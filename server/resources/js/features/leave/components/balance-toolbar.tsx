import {
    FilterSelect,
    ListToolbar,
    SearchInput,
} from '@/components/data-table';
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
    onReset: () => void;
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
    onReset,
}: Props) {
    return (
        <ListToolbar
            filtered={search !== '' || department !== null}
            onReset={onReset}
        >
            <SearchInput
                value={search}
                onSearch={onSearch}
                placeholder="Search employees…"
                label="Search employees"
            />
            <FilterSelect
                label="Filter by department"
                value={department ? String(department) : 'all'}
                onChange={(value) =>
                    onDepartment(value === 'all' ? null : Number(value))
                }
                options={[
                    { value: 'all', label: 'All departments' },
                    ...departments.map((d) => ({
                        value: String(d.id),
                        label: d.name,
                    })),
                ]}
                className="w-44"
            />
            <FilterSelect
                label="Select year"
                value={String(year)}
                onChange={(value) => onYear(Number(value))}
                options={years.map((y) => ({
                    value: String(y),
                    label: String(y),
                }))}
                className="w-28"
            />
        </ListToolbar>
    );
}
