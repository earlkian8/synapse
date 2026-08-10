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
    // App access (ADR 0026). HR invites people; it can never set a password.
    access: '/employees/access',
    invite: (id: number) => `/employees/${id}/invite`,
    approveJoinRequest: (id: number) =>
        `/employees/join-requests/${id}/approve`,
    declineJoinRequest: (id: number) =>
        `/employees/join-requests/${id}/decline`,
    // The company join code is a Company Setup setting (it belongs to the
    // organisation, not the roster) but it is only ever surfaced on App Access.
    joinCode: '/setup/company/join-code',
    restore: (id: number) => `/employees/${id}/restore`,
    forceDelete: (id: number) => `/employees/${id}/force`,
    documents: (id: number) => `/employees/${id}/documents`,
    document: (id: number, docId: number) =>
        `/employees/${id}/documents/${docId}`,
    certifications: (id: number) => `/employees/${id}/certifications`,
    certification: (id: number, certId: number) =>
        `/employees/${id}/certifications/${certId}`,
} as const;
