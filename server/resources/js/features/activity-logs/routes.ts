/**
 * Centralised endpoint map for the Activity Logs module.
 * Mirrors the named routes registered in routes/system.php.
 */
export const activityLogRoutes = {
    index: '/system/activity-logs',
    bulk: '/system/activity-logs/bulk',
    export: '/system/activity-logs/export',
    clear: '/system/activity-logs/clear',
    destroy: (id: number) => `/system/activity-logs/${id}`,
} as const;
