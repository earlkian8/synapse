/**
 * Centralised endpoint map for the Roles & Permissions module.
 * Mirrors the named routes registered in routes/system.php.
 */
export const roleRoutes = {
    index: '/system/roles',
    store: '/system/roles',
    bulk: '/system/roles/bulk',
    export: '/system/roles/export',
    update: (id: number) => `/system/roles/${id}`,
    destroy: (id: number) => `/system/roles/${id}`,
} as const;
