import type {
    ApplicantSource,
    EmploymentType,
    InterviewMode,
    InterviewResult,
    PostingStatus,
    Stage,
} from './types';

export const STATUS_FILTERS = [
    { value: 'all', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'open', label: 'Open' },
    { value: 'closed', label: 'Closed' },
    { value: 'filled', label: 'Filled' },
] as const;

export const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100] as const;

export const DEFAULT_FILTERS = {
    search: '',
    status: 'all',
    department: null,
    sort: 'created_at',
    direction: 'desc',
    per_page: 10,
} as const;

export const EMPLOYMENT_TYPE_OPTIONS: {
    value: EmploymentType;
    label: string;
}[] = [
    { value: 'regular', label: 'Regular' },
    { value: 'probationary', label: 'Probationary' },
    { value: 'contractual', label: 'Contractual' },
    { value: 'part_time', label: 'Part-time' },
];

export const TYPE_LABELS: Record<EmploymentType, string> = {
    regular: 'Regular',
    probationary: 'Probationary',
    contractual: 'Contractual',
    part_time: 'Part-time',
};

export const POSTING_STATUS_OPTIONS: { value: PostingStatus; label: string }[] =
    [
        { value: 'draft', label: 'Draft' },
        { value: 'open', label: 'Open' },
        { value: 'closed', label: 'Closed' },
        { value: 'filled', label: 'Filled' },
    ];

export const POSTING_STATUS_LABELS: Record<PostingStatus, string> = {
    draft: 'Draft',
    open: 'Open',
    closed: 'Closed',
    filled: 'Filled',
};

export const POSTING_STATUS_STYLES: Record<PostingStatus, string> = {
    draft: 'border-slate-500/30 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    open: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    closed: 'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
    filled: 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
};

/** The ordered pipeline columns shown on the board. */
export const PIPELINE_STAGES: {
    value: Stage;
    label: string;
    accent: string;
    dot: string;
}[] = [
    { value: 'applied', label: 'Applied', accent: 'text-slate-600', dot: 'bg-slate-400' },
    { value: 'screening', label: 'Screening', accent: 'text-indigo-600', dot: 'bg-indigo-400' },
    { value: 'interview', label: 'Interview', accent: 'text-violet-600', dot: 'bg-violet-400' },
    { value: 'offer', label: 'Offer', accent: 'text-amber-600', dot: 'bg-amber-400' },
    { value: 'hired', label: 'Hired', accent: 'text-emerald-600', dot: 'bg-emerald-400' },
    { value: 'rejected', label: 'Rejected', accent: 'text-rose-600', dot: 'bg-rose-400' },
];

/** Stages a recruiter may move a card to directly (terminal stages excluded). */
export const MOVABLE_STAGES: { value: Stage; label: string }[] = [
    { value: 'applied', label: 'Applied' },
    { value: 'screening', label: 'Screening' },
    { value: 'interview', label: 'Interview' },
    { value: 'offer', label: 'Offer' },
];

export const STAGE_LABELS: Record<Stage, string> = {
    applied: 'Applied',
    screening: 'Screening',
    interview: 'Interview',
    offer: 'Offer',
    hired: 'Hired',
    rejected: 'Rejected',
};

export const STAGE_STYLES: Record<Stage, string> = {
    applied: 'border-slate-500/30 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    screening:
        'border-indigo-500/30 bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
    interview:
        'border-violet-500/30 bg-violet-500/10 text-violet-600 dark:text-violet-400',
    offer: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    hired: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    rejected:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
};

export const SOURCE_OPTIONS: { value: ApplicantSource; label: string }[] = [
    { value: 'website', label: 'Website' },
    { value: 'referral', label: 'Referral' },
    { value: 'linkedin', label: 'LinkedIn' },
    { value: 'agency', label: 'Agency' },
    { value: 'walk_in', label: 'Walk-in' },
    { value: 'other', label: 'Other' },
];

export const SOURCE_LABELS: Record<ApplicantSource, string> = {
    website: 'Website',
    referral: 'Referral',
    linkedin: 'LinkedIn',
    agency: 'Agency',
    walk_in: 'Walk-in',
    other: 'Other',
};

export const MODE_OPTIONS: { value: InterviewMode; label: string }[] = [
    { value: 'onsite', label: 'On-site' },
    { value: 'online', label: 'Online' },
    { value: 'phone', label: 'Phone' },
];

export const MODE_LABELS: Record<InterviewMode, string> = {
    onsite: 'On-site',
    online: 'Online',
    phone: 'Phone',
};

export const RESULT_OPTIONS: { value: InterviewResult; label: string }[] = [
    { value: 'pending', label: 'Pending' },
    { value: 'passed', label: 'Passed' },
    { value: 'failed', label: 'Failed' },
];

export const RESULT_STYLES: Record<InterviewResult, string> = {
    pending: 'border-amber-500/30 bg-amber-500/10 text-amber-600',
    passed: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600',
    failed: 'border-rose-500/30 bg-rose-500/10 text-rose-600',
};
