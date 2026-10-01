/**
 * Endpoint map for the Help Center.
 * Mirrors the named routes registered in routes/help.php (help.*).
 */
export const helpRoutes = {
    index: '/help',
    search: '/help/search',
    /** "Help for this page": the server redirects to the matching article. */
    forPage: (path: string) => `/help/for?path=${encodeURIComponent(path)}`,
} as const;
