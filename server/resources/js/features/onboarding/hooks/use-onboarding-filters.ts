import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { DEFAULT_FILTERS } from '../constants';
import { onboardingRoutes } from '../routes';
import type { OnboardingFilters } from '../types';

type FilterOverrides = Partial<OnboardingFilters>;

/**
 * Drives the onboarding board's server-side filters (search, status, department)
 * through Inertia partial reloads.
 */
export function useOnboardingFilters(filters: OnboardingFilters) {
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

            if (next.department) {
                query.department = next.department;
            }

            router.get(onboardingRoutes.index, query, {
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
    const setDepartment = useCallback(
        (department: number | null) => apply({ department }),
        [apply],
    );

    const reset = useCallback(() => {
        router.get(
            onboardingRoutes.index,
            {},
            {
                preserveScroll: true,
                replace: true,
                only: ['cases', 'stats', 'filters'],
            },
        );
    }, []);

    return { setSearch, setStatus, setDepartment, reset };
}
