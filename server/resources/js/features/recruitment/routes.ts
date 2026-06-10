/**
 * Centralised endpoint map for the Recruitment module.
 * Mirrors the named routes registered in routes/recruitment.php.
 */
export const recruitmentRoutes = {
    index: '/recruitment',
    store: '/recruitment',
    export: '/recruitment/export',
    show: (id: number) => `/recruitment/${id}`,
    update: (id: number) => `/recruitment/${id}`,
    destroy: (id: number) => `/recruitment/${id}`,
    status: (id: number) => `/recruitment/${id}/status`,

    applicants: '/recruitment/applicants',
    applicant: (id: number) => `/recruitment/applicants/${id}`,

    applications: (postingId: number) =>
        `/recruitment/${postingId}/applications`,
    application: (id: number) => `/recruitment/applications/${id}`,
    applicationStage: (id: number) =>
        `/recruitment/applications/${id}/stage`,
    applicationReject: (id: number) =>
        `/recruitment/applications/${id}/reject`,
    applicationHire: (id: number) => `/recruitment/applications/${id}/hire`,
    applicationInterviews: (id: number) =>
        `/recruitment/applications/${id}/interviews`,

    interview: (id: number) => `/recruitment/interviews/${id}`,
} as const;
