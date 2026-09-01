/**
 * Centralised endpoint map for Company Setup's Recruitment Pipelines screen.
 * Mirrors the named routes registered in routes/setup.php.
 */
export const recruitmentPipelineRoutes = {
    index: '/setup/recruitment-pipelines',
    store: '/setup/recruitment-pipelines',
    // Pipelines are addressed by their obfuscated hashid (see App\Support\Hashid).
    update: (hashid: string) => `/setup/recruitment-pipelines/${hashid}`,
    destroy: (hashid: string) => `/setup/recruitment-pipelines/${hashid}`,
} as const;
