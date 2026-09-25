import type { WizardView } from './types';

/**
 * Endpoint map for the company setup wizard.
 * Mirrors the named routes registered in routes/setup.php (setup.wizard.*).
 */
export const setupWizardRoutes = {
    show: '/setup/wizard',
    /** One view of the wizard — the welcome, a step, or the send-off. */
    view: (view: WizardView) => `/setup/wizard/${view}`,
    company: '/setup/wizard/company',
    departments: '/setup/wizard/departments',
    'leave-types': '/setup/wizard/leave-types',
    attendance: '/setup/wizard/attendance',
    holidays: '/setup/wizard/holidays',
    recruitment: '/setup/wizard/recruitment',
    onboarding: '/setup/wizard/onboarding',
    performance: '/setup/wizard/performance',
    'award-types': '/setup/wizard/award-types',
    offboarding: '/setup/wizard/offboarding',
    continue: '/setup/wizard/continue',
    skip: '/setup/wizard/skip',
    finish: '/setup/wizard/finish',
} as const;
