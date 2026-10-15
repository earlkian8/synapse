/**
 * Endpoint map for one's own workspaces (ADR 0089).
 * Mirrors the named routes in routes/web.php and routes/settings.php.
 */
export const workspaceRoutes = {
    picker: '/workspaces',
    store: '/workspaces',
    switch: '/organization/switch',
    manage: '/settings/workspaces',
    leave: (id: number) => `/settings/workspaces/${id}`,
} as const;
