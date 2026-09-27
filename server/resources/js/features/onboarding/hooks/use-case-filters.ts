import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { DEFAULT_FILTERS } from '../constants';
import type { CaseFilters, CaseSort } from '../types';

type Overrides = Partial<CaseFilters & { page: number }>;

/**
 * Drives one program's case table — search, status, department, sort and paging —
 * through Inertia partial reloads of the page at `url`, the way the Employees
 * table is driven.
 */
export function useCaseFilters(url: string, filters: CaseFilters) {
    const apply = useCallback(
        (overrides: Overrides) => {
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

            if (next.sort) {
                query.sort = next.sort;
                query.direction = next.direction;
            }

            if (next.per_page !== DEFAULT_FILTERS.per_page) {
                query.per_page = next.per_page;
            }

            if (overrides.page && overrides.page > 1) {
                query.page = overrides.page;
            }

            router.get(url, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['cases', 'filters'],
            });
        },
        [url, filters],
    );

    return {
        setSearch: (search: string) => apply({ search, page: 1 }),
        setStatus: (status: string) => apply({ status, page: 1 }),
        setDepartment: (department: number | null) =>
            apply({ department, page: 1 }),
        setPerPage: (per_page: number) => apply({ per_page, page: 1 }),
        setPage: (page: number) => apply({ page }),
        // First click sorts ascending, the second descending, the third clears.
        toggleSort: (column: CaseSort) =>
            filters.sort !== column
                ? apply({ sort: column, direction: 'asc', page: 1 })
                : filters.direction === 'asc'
                  ? apply({ sort: column, direction: 'desc', page: 1 })
                  : apply({ sort: null, direction: 'asc', page: 1 }),
        reset: () =>
            router.get(
                url,
                {},
                {
                    preserveScroll: true,
                    replace: true,
                    only: ['cases', 'filters'],
                },
            ),
    };
}
