/**
 * Centralised endpoint map for the Employee module.
 * Mirrors the named routes registered in routes/employees.php.
 */
export const employeeRoutes = {
    index: '/employees',
    store: '/employees',
    bulk: '/employees/bulk',
    export: '/employees/export',
    show: (id: number) => `/employees/${id}`,
    update: (id: number) => `/employees/${id}`,
    destroy: (id: number) => `/employees/${id}`,
    status: (id: number) => `/employees/${id}/status`,
    restore: (id: number) => `/employees/${id}/restore`,
    forceDelete: (id: number) => `/employees/${id}/force`,
    documents: (id: number) => `/employees/${id}/documents`,
    document: (id: number, docId: number) =>
        `/employees/${id}/documents/${docId}`,
    certifications: (id: number) => `/employees/${id}/certifications`,
    certification: (id: number, certId: number) =>
        `/employees/${id}/certifications/${certId}`,
} as const;
