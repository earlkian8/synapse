export const STATUS_FILTERS = [
    { value: 'all', label: 'All users' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'unverified', label: 'Unverified' },
    { value: 'archived', label: 'Archived' },
] as const;

export const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100] as const;

export const DEFAULT_FILTERS = {
    search: '',
    status: 'all',
    role: null,
    sort: 'created_at',
    direction: 'desc',
    per_page: 10,
} as const;

/** Sentinel for "all roles" in the role filter <Select> (empty value is invalid). */
export const ALL_ROLES = 'all';
