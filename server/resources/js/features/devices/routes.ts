/**
 * Endpoint map for Company Setup → Devices (ADR 0040).
 * Mirrors the named routes in routes/setup.php (devices.*), addressed by hashid.
 */
export const deviceRoutes = {
    index: '/setup/devices',
    store: '/setup/devices',
    update: (hashid: string) => `/setup/devices/${hashid}`,
    rotateKey: (hashid: string) => `/setup/devices/${hashid}/key`,
    importCsv: (hashid: string) => `/setup/devices/${hashid}/import`,
    destroy: (hashid: string) => `/setup/devices/${hashid}`,
} as const;
