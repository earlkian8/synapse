/**
 * Endpoint map for Company Setup → Attendance Policies.
 * Mirrors the named routes in routes/setup.php (attendance-policies.*).
 * Policies are addressed by hashid; restore / force-delete take it as a string.
 */
export const attendancePolicyRoutes = {
    index: '/setup/attendance-policies',
    store: '/setup/attendance-policies',
    /** The worked example — judges a sample day, writes nothing. */
    preview: '/setup/attendance-policies/preview',
    update: (hashid: string) => `/setup/attendance-policies/${hashid}`,
    setDefault: (hashid: string) =>
        `/setup/attendance-policies/${hashid}/default`,
    destroy: (hashid: string) => `/setup/attendance-policies/${hashid}`,
    restore: (hashid: string) => `/setup/attendance-policies/${hashid}/restore`,
    forceDelete: (hashid: string) =>
        `/setup/attendance-policies/${hashid}/force`,
} as const;
