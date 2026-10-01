/**
 * Centralised endpoint map for the Company Setup → Departments module.
 * Mirrors the named routes registered in routes/setup.php.
 */
export const departmentRoutes = {
    index: '/setup/departments',
    store: '/setup/departments',
    // Departments are addressed by their obfuscated hashid (App\Support\Hashid).
    department: (hashid: string) => `/setup/departments/${hashid}`,
    restore: (hashid: string) => `/setup/departments/${hashid}/restore`,
    forceDelete: (hashid: string) => `/setup/departments/${hashid}/force`,

    // Positions (sub-resources, addressed by numeric id).
    positions: (departmentHashid: string) =>
        `/setup/departments/${departmentHashid}/positions`,
    position: (id: number) => `/setup/positions/${id}`,
} as const;
