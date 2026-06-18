/**
 * Endpoint map for the Awards & Recognition module.
 * Mirrors the named routes in routes/awards.php. Awards are addressed by numeric id.
 */
export const awardsRoutes = {
    index: '/awards',
    store: '/awards',
    award: (id: number) => `/awards/${id}`,
} as const;
