export const TYPE_FILTERS = [
    { value: 'all', label: 'All roles' },
    { value: 'system', label: 'System' },
    { value: 'custom', label: 'Custom' },
] as const;

export const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100] as const;

export const DEFAULT_FILTERS = {
    search: '',
    type: 'all',
    sort: 'created_at',
    direction: 'desc',
    per_page: 10,
} as const;

/**
 * Accent colours per permission group, keyed by the group name returned from
 * the backend registry. Falls back to the brand teal for unknown groups.
 */
export const GROUP_ACCENTS: Record<string, string> = {
    'User Management': 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    'Roles & Permissions': 'text-indigo-600 bg-indigo-500/10',
    'Activity Logs': 'text-amber-600 bg-amber-500/10',
};

export const DEFAULT_GROUP_ACCENT = 'text-[#0ABFBF] bg-[#0ABFBF]/10';
