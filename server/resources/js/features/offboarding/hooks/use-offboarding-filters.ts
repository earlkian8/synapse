import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { DEFAULT_FILTERS } from '../constants';
import { offboardingRoutes } from '../routes';
import type { OffboardingFilters } from '../types';

type FilterOverrides = Partial<OffboardingFilters>;

/**
 * Drives the offboarding board's server-side filters (search, status, type,
 * department) through Inertia partial reloads.
 */
export function useOffboardingFilters(filters: OffboardingFilters) {
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

            router.get(offboardingRoutes.index, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['cases', 'stats', 'filters'],
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
        (type: string | null) => apply({ type }),
        [apply],
    );
    const setDepartment = useCallback(
        (department: number | null) => apply({ department }),
        [apply],
    );

    const reset = useCallback(() => {
        router.get(
            offboardingRoutes.index,
            {},
            {
                preserveScroll: true,
                replace: true,
                only: ['cases', 'stats', 'filters'],
            },
        );
    }, []);

    return { setSearch, setStatus, setType, setDepartment, reset };
}
