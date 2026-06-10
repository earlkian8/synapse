import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { DEFAULT_FILTERS } from '../constants';
import { roleRoutes } from '../routes';
import type { RolesFilters, SortDirection } from '../types';

type FilterOverrides = Partial<RolesFilters & { page: number }>;

/**
 * Drives the server-side table state (search, type, sort, paging) by pushing
 * query-string updates through Inertia partial reloads.
 */
export function useRolesFilters(filters: RolesFilters) {
    const apply = useCallback(
        (overrides: FilterOverrides) => {
            const next = { ...filters, ...overrides };
            const query: Record<string, string | number> = {};

            if (next.search.trim()) {
                query.search = next.search.trim();
            }

            if (next.type && next.type !== DEFAULT_FILTERS.type) {
                query.type = next.type;
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

            router.get(roleRoutes.index, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['roles', 'filters'],
            });
        },
        [filters],
    );

    const setSearch = useCallback(
        (search: string) => apply({ search, page: 1 }),
        [apply],
    );

    const setType = useCallback(
        (type: string) => apply({ type, page: 1 }),
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
            roleRoutes.index,
            {},
            { preserveScroll: true, replace: true, only: ['roles', 'filters'] },
        );
    }, []);

    return { setSearch, setType, setPerPage, setPage, toggleSort, reset };
}
