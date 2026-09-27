import { router } from '@inertiajs/react';
import { onboardingRoutes } from '../routes';

/** Searches the programs overview by name, through a partial reload. */
export function useProgramSearch() {
    return (search: string) =>
        router.get(
            onboardingRoutes.index,
            search.trim() ? { search: search.trim() } : {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['programs', 'filters'],
            },
        );
}
