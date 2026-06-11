import {
    Compass,
    FileText,
    GraduationCap,
    KeyRound,
    Laptop,
    
    ShieldCheck,
    Sparkles
} from 'lucide-react';
import type {LucideIcon} from 'lucide-react';
import type {
    CaseStatus,
    EmploymentType,
    TaskCategory,
    TaskStatus,
} from './types';

export const DEFAULT_FILTERS = {
    search: '',
    status: 'active',
    department: null,
} as const;

export const STATUS_FILTERS = [
    { value: 'active', label: 'Active' },
    { value: 'pending', label: 'Not started' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'all', label: 'All' },
] as const;

export const CASE_STATUS_LABELS: Record<CaseStatus, string> = {
    pending: 'Not started',
    in_progress: 'In progress',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const CASE_STATUS_STYLES: Record<CaseStatus, string> = {
    pending: 'border-slate-500/30 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    in_progress:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    completed:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    cancelled:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
};

export const TASK_STATUS_LABELS: Record<TaskStatus, string> = {
    pending: 'To do',
    in_progress: 'In progress',
    done: 'Done',
    skipped: 'Skipped',
};

/** The category presentation: label, icon and accent — used to group the checklist. */
export const CATEGORY_META: Record<
    TaskCategory,
    { label: string; icon: LucideIcon; accent: string }
> = {
    paperwork: {
        label: 'Paperwork',
        icon: FileText,
        accent: 'text-sky-600 bg-sky-500/10',
    },
    equipment: {
        label: 'Equipment',
        icon: Laptop,
        accent: 'text-violet-600 bg-violet-500/10',
    },
    access: {
        label: 'Access',
        icon: KeyRound,
        accent: 'text-amber-600 bg-amber-500/10',
    },
    orientation: {
        label: 'Orientation',
        icon: Compass,
        accent: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    },
    training: {
        label: 'Training',
        icon: GraduationCap,
        accent: 'text-indigo-600 bg-indigo-500/10',
    },
    compliance: {
        label: 'Compliance',
        icon: ShieldCheck,
        accent: 'text-emerald-600 bg-emerald-500/10',
    },
    other: {
        label: 'Other',
        icon: Sparkles,
        accent: 'text-slate-600 bg-slate-500/10',
    },
};

/** The order categories appear in on the checklist. */
export const CATEGORY_ORDER: TaskCategory[] = [
    'paperwork',
    'equipment',
    'access',
    'orientation',
    'training',
    'compliance',
    'other',
];

export const CATEGORY_OPTIONS: { value: TaskCategory; label: string }[] =
    CATEGORY_ORDER.map((value) => ({
        value,
        label: CATEGORY_META[value].label,
    }));

export const EMPLOYMENT_TYPE_LABELS: Record<EmploymentType, string> = {
    regular: 'Regular',
    probationary: 'Probationary',
    contractual: 'Contractual',
    part_time: 'Part-time',
};

export const EMPLOYMENT_TYPE_OPTIONS: {
    value: EmploymentType;
    label: string;
}[] = [
    { value: 'regular', label: 'Regular' },
    { value: 'probationary', label: 'Probationary' },
    { value: 'contractual', label: 'Contractual' },
    { value: 'part_time', label: 'Part-time' },
];
