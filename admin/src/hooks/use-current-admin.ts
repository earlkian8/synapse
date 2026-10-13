import { useMe } from '@/features/auth/api';
import type { Admin } from '@/lib/types';

/**
 * The signed-in admin, inside the guarded part of the portal — RequireSession
 * renders nothing there until `/me` has answered.
 */
export function useCurrentAdmin(): Admin {
    const { data } = useMe();

    if (!data) {
        throw new Error(
            'useCurrentAdmin() is only for pages behind RequireSession.',
        );
    }

    return data.admin;
}
