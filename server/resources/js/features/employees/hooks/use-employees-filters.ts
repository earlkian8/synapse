import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { DEFAULT_FILTERS } from '../constants';
import { employeeRoutes } from '../routes';
import type { EmployeesFilters, SortDirection } from '../types';

type FilterOverrides = Partial<EmployeesFilters & { page: number }>;

/**
 * Drives the server-side table state (search, status, type, department, sort,
 * paging) by pushing query-string updates through Inertia partial reloads.
 */
export function useEmployeesFilters(filters: EmployeesFilters) {
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

            if (next.type && next.type !== DEFAULT_FILTERS.type) {
                query.type = next.type;
            }

            if (next.department) {
                query.department = next.department;
            }

            if (next.sort && next.sort !== DEFAULT_FILTERS.sort) {
                query.sort = next.sort;
            }

            if (
                next.direction &&
                next.direction !== DEFAULT_FILTERS.direction
            ) {
                query.direction = next.direction;
            }

            if (next.per_page && next.per_page !== DEFAULT_FILTERS.per_page) {
                query.per_page = next.per_page;
            }

            if ('page' in overrides && overrides.page && overrides.page > 1) {
                query.page = overrides.page;
            }

            router.get(employeeRoutes.index, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['employees', 'filters'],
            });
        },
        [filters],
    );

    const setSearch = useCallback(
        (search: string) => apply({ search, page: 1 }),
        [apply],
    );
    const setStatus = useCallback(
        (status: string) => apply({ status, page: 1 }),
        [apply],
    );
    const setType = useCallback(
        (type: string) => apply({ type, page: 1 }),
        [apply],
    );
    const setDepartment = useCallback(
        (department: number | null) => apply({ department, page: 1 }),
        [apply],
    );
    const setPerPage = useCallback(
        (per_page: number) => apply({ per_page, page: 1 }),
        [apply],
    );
    const setPage = useCallback((page: number) => apply({ page }), [apply]);

    const toggleSort = useCallback(
        (column: string) => {
            const direction: SortDirection =
                filters.sort === column && filters.direction === 'asc'
                    ? 'desc'
                    : 'asc';
            apply({ sort: column, direction, page: 1 });
        },
        [apply, filters.sort, filters.direction],
    );

    const reset = useCallback(() => {
        router.get(
            employeeRoutes.index,
            {},
            {
                preserveScroll: true,
                replace: true,
                only: ['employees', 'filters'],
            },
        );
    }, []);

    return {
        setSearch,
        setStatus,
        setType,
        setDepartment,
        setPerPage,
        setPage,
        toggleSort,
        reset,
    };
}
