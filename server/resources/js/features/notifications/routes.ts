/**
 * Centralised endpoint map for the Notifications module.
 * Mirrors the named routes registered in routes/notifications.php.
 */
export const notificationRoutes = {
    index: '/system/notifications',
    store: '/system/notifications',
    readAll: '/system/notifications/read-all',
    clear: '/system/notifications/clear',
    read: (id: string) => `/system/notifications/${id}/read`,
    destroy: (id: string) => `/system/notifications/${id}`,
    preferences: '/system/notifications/preferences',
    subscribe: '/system/notifications/subscriptions',
    unsubscribe: '/system/notifications/subscriptions',
} as const;
