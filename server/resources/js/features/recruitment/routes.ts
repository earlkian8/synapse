/**
 * Centralised endpoint map for the Recruitment module.
 * Mirrors the named routes registered in routes/recruitment.php.
 */
export const recruitmentRoutes = {
    index: '/recruitment',
    store: '/recruitment',
    export: '/recruitment/export',
    // Postings are addressed by their obfuscated hashid (see App\Support\Hashid).
    show: (hashid: string) => `/recruitment/${hashid}`,
    pipelineExport: (hashid: string) => `/recruitment/${hashid}/export`,
    update: (hashid: string) => `/recruitment/${hashid}`,
    destroy: (hashid: string) => `/recruitment/${hashid}`,
    status: (hashid: string) => `/recruitment/${hashid}/status`,

    applicants: '/recruitment/applicants',
    applicant: (id: number) => `/recruitment/applicants/${id}`,

    applications: (postingHashid: string) =>
        `/recruitment/${postingHashid}/applications`,
    application: (id: number) => `/recruitment/applications/${id}`,
    applicationInsights: (id: number) =>
        `/recruitment/applications/${id}/insights`,
    applicationStage: (id: number) => `/recruitment/applications/${id}/stage`,
    applicationReject: (id: number) => `/recruitment/applications/${id}/reject`,
    applicationHire: (id: number) => `/recruitment/applications/${id}/hire`,
    applicationInterviews: (id: number) =>
        `/recruitment/applications/${id}/interviews`,

    interview: (id: number) => `/recruitment/interviews/${id}`,
} as const;
