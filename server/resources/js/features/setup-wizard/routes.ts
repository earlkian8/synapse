/**
 * Endpoint map for the company setup wizard.
 * Mirrors the named routes registered in routes/setup.php (setup.wizard.*).
 */
export const setupWizardRoutes = {
    show: '/setup/wizard',
    company: '/setup/wizard/company',
    departments: '/setup/wizard/departments',
    'leave-types': '/setup/wizard/leave-types',
    attendance: '/setup/wizard/attendance',
    recruitment: '/setup/wizard/recruitment',
    performance: '/setup/wizard/performance',
    skip: '/setup/wizard/skip',
    finish: '/setup/wizard/finish',
} as const;
