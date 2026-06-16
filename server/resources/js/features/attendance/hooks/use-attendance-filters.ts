import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { DEFAULT_STATUS } from '../constants';
import { attendanceRoutes } from '../routes';
import type { AttendanceFilters, AttendanceTab } from '../types';

type FilterOverrides = Partial<AttendanceFilters>;

/** The dataset prop each tab pulls, so partial reloads stay scoped. */
const TAB_PROP: Record<AttendanceTab, string> = {
    today: 'records',
    weekly: 'week',
    monthly: 'report',
};

/**
 * Drives the attendance workspace's server-side filters (tab, date, search,
 * status, department) through Inertia partial reloads — only the active tab's
 * dataset (plus stats + filters) is refetched.
 */
export function useAttendanceFilters(filters: AttendanceFilters) {
    const apply = useCallback(
        (overrides: FilterOverrides) => {
            const next = { ...filters, ...overrides };
            const query: Record<string, string | number> = { date: next.date };

            if (next.tab !== 'today') {
                query.tab = next.tab;
            }

            if (next.search.trim()) {
                query.search = next.search.trim();
            }

            if (next.tab === 'today' && next.status !== DEFAULT_STATUS) {
                query.status = next.status;
            }

            if (next.department) {
                query.department = next.department;
            }

            router.get(attendanceRoutes.index, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['stats', 'filters', TAB_PROP[next.tab]],
            });
        },
        [filters],
    );

    const setTab = useCallback((tab: AttendanceTab) => apply({ tab }), [apply]);
    const setDate = useCallback((date: string) => apply({ date }), [apply]);
    // Jump to a specific day's log (e.g. from a weekly-grid cell).
    const goToDay = useCallback(
        (date: string) => apply({ tab: 'today', date }),
        [apply],
    );
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

    return { setTab, setDate, goToDay, setSearch, setStatus, setDepartment };
}
