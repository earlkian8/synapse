import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import type { Auth } from '@/types';

type SharedAuth = {
    auth?: Partial<Auth>;
};

/**
 * Read the current user's roles & permissions from the shared Inertia props.
 *
 * Mirrors the server-side gate: super admins satisfy every check. These helpers
 * drive UI affordances only — every action is still enforced on the backend.
 */
export function usePermissions() {
    const { auth } = usePage<SharedAuth>().props;

    return useMemo(() => {
        const permissions = auth?.permissions ?? [];
        const roles = auth?.roles ?? [];
        const isSuperAdmin = auth?.is_super_admin ?? false;

        const can = (permission: string): boolean =>
            isSuperAdmin || permissions.includes(permission);

        const canAny = (...checks: string[]): boolean =>
            isSuperAdmin ||
            checks.some((permission) => permissions.includes(permission));

        const hasRole = (role: string): boolean => roles.includes(role);

        return { can, canAny, hasRole, isSuperAdmin, permissions, roles };
    }, [auth]);
}
