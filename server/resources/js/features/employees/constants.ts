import type { EmployeeStatus, EmploymentType } from './types';

export const STATUS_FILTERS = [
    { value: 'all', label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'on_leave', label: 'On leave' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'resigned', label: 'Resigned' },
    { value: 'terminated', label: 'Terminated' },
    { value: 'archived', label: 'Archived' },
] as const;

export const TYPE_FILTERS = [
    { value: 'all', label: 'All types' },
    { value: 'regular', label: 'Regular' },
    { value: 'probationary', label: 'Probationary' },
    { value: 'contractual', label: 'Contractual' },
    { value: 'part_time', label: 'Part-time' },
] as const;

export const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100] as const;

export const DEFAULT_FILTERS = {
    search: '',
    status: 'all',
    type: 'all',
    department: null,
    sort: 'created_at',
    direction: 'desc',
    per_page: 10,
} as const;

export const EMPLOYMENT_STATUS_OPTIONS: {
    value: Exclude<EmployeeStatus, 'archived'>;
    label: string;
}[] = [
    { value: 'active', label: 'Active' },
    { value: 'on_leave', label: 'On leave' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'resigned', label: 'Resigned' },
    { value: 'terminated', label: 'Terminated' },
];

export const EMPLOYMENT_TYPE_OPTIONS: {
    value: EmploymentType;
    label: string;
}[] = [
    { value: 'regular', label: 'Regular' },
    { value: 'probationary', label: 'Probationary' },
    { value: 'contractual', label: 'Contractual' },
    { value: 'part_time', label: 'Part-time' },
];

export const GENDER_OPTIONS = [
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
    { value: 'other', label: 'Other' },
] as const;

export const CIVIL_STATUS_OPTIONS = [
    { value: 'single', label: 'Single' },
    { value: 'married', label: 'Married' },
    { value: 'widowed', label: 'Widowed' },
    { value: 'separated', label: 'Separated' },
    { value: 'divorced', label: 'Divorced' },
] as const;

export const DOCUMENT_TYPE_OPTIONS = [
    { value: 'contract', label: 'Contract' },
    { value: 'cv', label: 'CV / Résumé' },
    { value: 'govt_id', label: 'Government ID' },
    { value: 'other', label: 'Other' },
] as const;

/** Badge styles keyed by employment status. */
export const STATUS_STYLES: Record<EmployeeStatus, string> = {
    active: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    on_leave:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    suspended:
        'border-orange-500/30 bg-orange-500/10 text-orange-600 dark:text-orange-400',
    resigned:
        'border-slate-500/30 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    terminated:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
    archived:
        'border-slate-500/30 bg-slate-500/10 text-slate-600 dark:text-slate-300',
};

export const STATUS_LABELS: Record<EmployeeStatus, string> = {
    active: 'Active',
    on_leave: 'On leave',
    suspended: 'Suspended',
    resigned: 'Resigned',
    terminated: 'Terminated',
    archived: 'Archived',
};

export const TYPE_LABELS: Record<EmploymentType, string> = {
    regular: 'Regular',
    probationary: 'Probationary',
    contractual: 'Contractual',
    part_time: 'Part-time',
};
