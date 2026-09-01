import type {
    ApplicantSource,
    EmploymentType,
    FitBand,
    InterviewMode,
    InterviewResult,
    PostingStatus,
    StageKind,
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

/**
 * Stage colour now derives from what a stage *means* (open/won/lost) rather
 * than its name — a pipeline's stages are tenant-defined free text, so there's
 * no fixed name list to key a colour off any more. The stage's own name is
 * always shown as the label; this just tints it.
 */
export const STAGE_KIND_STYLES: Record<StageKind, string> = {
    open: 'border-indigo-500/30 bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
    won: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    lost: 'border-slate-500/30 bg-slate-500/10 text-slate-500 dark:text-slate-400',
};

export const STAGE_KIND_DOT: Record<StageKind, string> = {
    open: 'bg-indigo-400',
    won: 'bg-emerald-400',
    lost: 'bg-slate-400',
};

export const STAGE_KIND_LABELS: Record<StageKind, string> = {
    open: 'In progress',
    won: 'This is the Hired stage',
    lost: 'This is a Rejected / lost stage',
};

/** Fit-band labels and colour treatments for the automatic ranking score. */
export const FIT_BAND_LABELS: Record<FitBand, string> = {
    strong: 'Strong fit',
    promising: 'Promising',
    fair: 'Fair',
    weak: 'Weak',
};

export const FIT_BAND_STYLES: Record<FitBand, string> = {
    strong: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    promising: 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
    fair: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    weak: 'border-slate-500/30 bg-slate-500/10 text-slate-500 dark:text-slate-400',
};

/** The progress-meter fill colour per band. */
export const FIT_BAND_BARS: Record<FitBand, string> = {
    strong: 'bg-emerald-500',
    promising: 'bg-sky-500',
    fair: 'bg-amber-500',
    weak: 'bg-slate-400',
};

/** Colour treatment for the decision-panel recommendation, keyed by tone. */
export const RECOMMENDATION_STYLES: Record<
    'positive' | 'neutral' | 'caution',
    string
> = {
    positive:
        'border-emerald-500/30 bg-emerald-500/5 text-emerald-700 dark:text-emerald-300',
    neutral: 'border-sky-500/30 bg-sky-500/5 text-sky-700 dark:text-sky-300',
    caution:
        'border-amber-500/30 bg-amber-500/5 text-amber-700 dark:text-amber-300',
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
