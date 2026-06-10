/**
 * Centralised endpoint map for the Roles & Permissions module.
 * Mirrors the named routes registered in routes/system.php.
 */
export const roleRoutes = {
    index: '/system/roles',
    store: '/system/roles',
    export: '/system/roles/export',
    update: (id: number) => `/system/roles/${id}`,
    destroy: (id: number) => `/system/roles/${id}`,
} as const;
