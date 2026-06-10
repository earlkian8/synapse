/**
 * Centralised endpoint map for the Notifications module.
 * Mirrors the named routes registered in routes/notifications.php.
 */
export const notificationRoutes = {
    index: '/notifications',
    store: '/notifications',
    readAll: '/notifications/read-all',
    clear: '/notifications/clear',
    read: (id: string) => `/notifications/${id}/read`,
    destroy: (id: string) => `/notifications/${id}`,
    preferences: '/notifications/preferences',
    subscribe: '/notifications/subscriptions',
    unsubscribe: '/notifications/subscriptions',
} as const;
