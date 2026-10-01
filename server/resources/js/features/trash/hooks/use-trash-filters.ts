import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { trashRoutes } from '../routes';
import type { TrashFilters } from '../types';

type FilterOverrides = Partial<TrashFilters & { page: number }>;

/**
 * Drives the server-side trash listing (search, type, paging) by pushing
 * query-string updates through Inertia partial reloads.
 */
export function useTrashFilters(filters: TrashFilters) {
    const apply = useCallback(
        (overrides: FilterOverrides) => {
            const next = { ...filters, ...overrides };
            const query: Record<string, string | number> = {};

            if (next.search.trim()) {
                query.search = next.search.trim();
            }

            if (next.type && next.type !== 'all') {
                query.type = next.type;
            }

            if (next.per_page && next.per_page !== 15) {
                query.per_page = next.per_page;
            }

            if ('page' in overrides && overrides.page && overrides.page > 1) {
                query.page = overrides.page;
            }

            router.get(trashRoutes.index, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['items', 'filters', 'summary'],
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

    return { setSearch, setType, setPerPage, setPage };
}
