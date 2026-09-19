/**
 * Endpoint map for Company Setup → Locations (ADR 0040).
 * Mirrors the named routes in routes/setup.php (locations.*). Locations are
 * addressed by hashid; restore / force-delete take it as a string.
 */
export const locationRoutes = {
    index: '/setup/locations',
    store: '/setup/locations',
    update: (hashid: string) => `/setup/locations/${hashid}`,
    people: (hashid: string) => `/setup/locations/${hashid}/people`,
    destroy: (hashid: string) => `/setup/locations/${hashid}`,
    restore: (hashid: string) => `/setup/locations/${hashid}/restore`,
    forceDelete: (hashid: string) => `/setup/locations/${hashid}/force`,
} as const;

/** OpenStreetMap's geocoder, asked only when somebody searches. */
export const GEOCODER_URL = 'https://nominatim.openstreetmap.org/search';
