import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { DEFAULT_FILTERS } from '../constants';
import { activityLogRoutes } from '../routes';
import type { ActivityFilters, SortDirection } from '../types';

type FilterOverrides = Partial<ActivityFilters & { page: number }>;

/**
 * Drives the server-side table state (search, event, sort, paging) by
 * pushing query-string updates through Inertia partial reloads.
 */
export function useActivityLogsFilters(filters: ActivityFilters) {
    const apply = useCallback(
        (overrides: FilterOverrides) => {
            const next = { ...filters, ...overrides };
            const query: Record<string, string | number> = {};

            if (next.search.trim()) {
                query.search = next.search.trim();
            }

            if (next.event && next.event !== DEFAULT_FILTERS.event) {
                query.event = next.event;
            }

            if (next.sort && next.sort !== DEFAULT_FILTERS.sort) {
                query.sort = next.sort;
            }

            if (next.direction && next.direction !== DEFAULT_FILTERS.direction) {
                query.direction = next.direction;
            }

            if (next.per_page && next.per_page !== DEFAULT_FILTERS.per_page) {
                query.per_page = next.per_page;
            }

            // Any filter change other than an explicit page move resets to page 1.
            if ('page' in overrides && overrides.page && overrides.page > 1) {
                query.page = overrides.page;
            }

            router.get(activityLogRoutes.index, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['logs', 'filters'],
            });
        },
        [filters],
    );

    const setSearch = useCallback(
        (search: string) => apply({ search, page: 1 }),
        [apply],
    );

    const setEvent = useCallback(
        (event: string) => apply({ event, page: 1 }),
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
            activityLogRoutes.index,
            {},
            { preserveScroll: true, replace: true, only: ['logs', 'filters'] },
        );
    }, []);

    return {
        setSearch,
        setEvent,
        setPerPage,
        setPage,
        toggleSort,
        reset,
    };
}
