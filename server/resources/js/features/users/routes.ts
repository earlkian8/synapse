/**
 * Centralised endpoint map for the User Management module.
 * Mirrors the named routes registered in routes/system.php.
 */
export const userRoutes = {
    index: '/system/users',
    store: '/system/users',
    bulk: '/system/users/bulk',
    export: '/system/users/export',
    importTemplate: '/system/users/import/template',
    import: '/system/users/import',
    update: (id: number) => `/system/users/${id}`,
    resendVerification: (id: number) =>
        `/system/users/${id}/resend-verification`,
    destroy: (id: number) => `/system/users/${id}`,
    status: (id: number) => `/system/users/${id}/status`,
    password: (id: number) => `/system/users/${id}/password`,
    restore: (id: number) => `/system/users/${id}/restore`,
    forceDelete: (id: number) => `/system/users/${id}/force`,
} as const;
