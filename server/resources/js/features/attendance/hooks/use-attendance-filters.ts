import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { DEFAULT_STATUS } from '../constants';
import { attendanceRoutes } from '../routes';
import type { AttendanceFilters } from '../types';

type FilterOverrides = Partial<AttendanceFilters>;

/**
 * Drives the attendance board's server-side filters (date, search, status,
 * department) through Inertia partial reloads.
 */
export function useAttendanceFilters(filters: AttendanceFilters) {
    const apply = useCallback(
        (overrides: FilterOverrides) => {
            const next = { ...filters, ...overrides };
            const query: Record<string, string | number> = { date: next.date };

            if (next.search.trim()) {
                query.search = next.search.trim();
            }

            if (next.status && next.status !== DEFAULT_STATUS) {
                query.status = next.status;
            }

            if (next.department) {
                query.department = next.department;
            }

            router.get(attendanceRoutes.index, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['records', 'stats', 'filters'],
            });
        },
        [filters],
    );

    const setDate = useCallback((date: string) => apply({ date }), [apply]);
    const setSearch = useCallback(
        (search: string) => apply({ search }),
        [apply],
    );
    const setStatus = useCallback(
        (status: string) => apply({ status }),
        [apply],
    );
    const setDepartment = useCallback(
        (department: number | null) => apply({ department }),
        [apply],
    );

    return { setDate, setSearch, setStatus, setDepartment };
}
