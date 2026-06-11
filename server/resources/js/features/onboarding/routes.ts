/**
 * Centralised endpoint map for the Onboarding module.
 * Mirrors the named routes registered in routes/onboarding.php.
 */
export const onboardingRoutes = {
    index: '/onboarding',
    store: '/onboarding',
    // Cases & programs are addressed by their obfuscated hashid (App\Support\Hashid).
    show: (hashid: string) => `/onboarding/${hashid}`,
    update: (hashid: string) => `/onboarding/${hashid}`,
    status: (hashid: string) => `/onboarding/${hashid}/status`,
    destroy: (hashid: string) => `/onboarding/${hashid}`,
    tasks: (caseHashid: string) => `/onboarding/${caseHashid}/tasks`,

    task: (id: number) => `/onboarding/tasks/${id}`,
    taskStatus: (id: number) => `/onboarding/tasks/${id}/status`,

    programs: '/onboarding/programs',
    programsStore: '/onboarding/programs',
    program: (hashid: string) => `/onboarding/programs/${hashid}`,
} as const;
