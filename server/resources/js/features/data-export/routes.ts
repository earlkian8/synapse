/**
 * Endpoint map for the Data Export module.
 * Mirrors the named routes in routes/system.php. Exports are addressed by hashid.
 */
export const dataExportRoutes = {
    index: '/system/data-export',
    store: '/system/data-export',
    download: (hashid: string) => `/system/data-export/${hashid}/download`,
    destroy: (hashid: string) => `/system/data-export/${hashid}`,
} as const;
