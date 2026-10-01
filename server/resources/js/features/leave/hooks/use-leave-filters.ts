import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { DEFAULT_FILTERS } from '../constants';
import { leaveRoutes } from '../routes';
import type { LeaveFilters } from '../types';

type FilterOverrides = Partial<LeaveFilters>;

/**
 * Drives the leave inbox's server-side filters (search, status, type, department)
 * through Inertia partial reloads.
 */
export function useLeaveFilters(filters: LeaveFilters) {
    const apply = useCallback(
        (overrides: FilterOverrides) => {
            const next = { ...filters, ...overrides };
            const query: Record<string, string | number> = {};

            if (next.search.trim()) {
                query.search = next.search.trim();
            }

            if (next.status && next.status !== DEFAULT_FILTERS.status) {
                query.status = next.status;
            }

            if (next.type) {
                query.type = next.type;
            }

            if (next.department) {
                query.department = next.department;
            }

            router.get(leaveRoutes.index, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['requests', 'stats', 'filters'],
            });
        },
        [filters],
    );

    const setSearch = useCallback(
        (search: string) => apply({ search }),
        [apply],
    );
    const setStatus = useCallback(
        (status: string) => apply({ status }),
        [apply],
    );
    const setType = useCallback(
        (type: number | null) => apply({ type }),
        [apply],
    );
    const setDepartment = useCallback(
        (department: number | null) => apply({ department }),
        [apply],
    );

    const reset = useCallback(() => {
        router.get(
            leaveRoutes.index,
            {},
            {
                preserveScroll: true,
                replace: true,
                only: ['requests', 'stats', 'filters'],
            },
        );
    }, []);

    return { setSearch, setStatus, setType, setDepartment, reset };
}
