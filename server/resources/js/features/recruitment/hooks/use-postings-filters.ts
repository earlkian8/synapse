import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { DEFAULT_FILTERS } from '../constants';
import { recruitmentRoutes } from '../routes';
import type { PostingsFilters, SortDirection } from '../types';

type FilterOverrides = Partial<PostingsFilters & { page: number }>;

/**
 * Drives the server-side postings table (search, status, department, sort,
 * paging) through Inertia partial reloads.
 */
export function usePostingsFilters(filters: PostingsFilters) {
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

            router.get(recruitmentRoutes.index, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['postings', 'filters'],
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
            recruitmentRoutes.index,
            {},
            {
                preserveScroll: true,
                replace: true,
                only: ['postings', 'filters'],
            },
        );
    }, []);

    return {
        setSearch,
        setStatus,
        setDepartment,
        setPerPage,
        setPage,
        toggleSort,
        reset,
    };
}
