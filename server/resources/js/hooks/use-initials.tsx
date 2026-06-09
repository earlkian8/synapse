import { useCallback } from 'react';

export type GetInitialsFn = (
    firstName?: string | null,
    lastName?: string | null,
) => string;

export function useInitials(): GetInitialsFn {
    return useCallback(
        (firstName?: string | null, lastName?: string | null): string => {
            const firstInitial = (firstName ?? '').trim().charAt(0);
            const lastInitial = (lastName ?? '').trim().charAt(0);

            return `${firstInitial}${lastInitial}`.toUpperCase();
        },
        [],
    );
}
