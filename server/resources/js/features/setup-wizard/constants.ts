import {
    Building2,
    CalendarRange,
    Clock,
    Network,
    Target,
    Workflow,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { SetupStep } from './types';

type StepMeta = {
    step: SetupStep;
    icon: LucideIcon;
    /** The short name in the step ladder. */
    label: string;
    /** The heading of the step itself. */
    title: string;
    /** One line saying what this step is for — and what depends on it. */
    purpose: string;
    /** Where the same thing is configured afterwards. */
    href: string;
};

/** The wizard's six steps, in the order the server walks them. */
export const STEPS: StepMeta[] = [
    {
        step: 'company',
        icon: Building2,
        label: 'Company',
        title: 'Tell us about your company',
        purpose:
            'The name, logo and details that appear on documents, job posts and payroll remittances.',
        href: '/setup/company',
    },
    {
        step: 'departments',
        icon: Network,
        label: 'Departments',
        title: 'How is your company organised?',
        purpose:
            'Every employee, job posting and appraisal is filed under a department.',
        href: '/setup/departments',
    },
    {
        step: 'leave-types',
        icon: CalendarRange,
        label: 'Leave',
        title: 'What leave do you grant?',
        purpose: 'Employees can only file the kinds of leave you define here.',
        href: '/setup/leave-types',
    },
    {
        step: 'attendance',
        icon: Clock,
        label: 'Attendance',
        title: 'How are your days judged?',
        purpose:
            'When someone is late or short, what counts as overtime, and the hours most people work.',
        href: '/setup/attendance-policies',
    },
    {
        step: 'recruitment',
        icon: Workflow,
        label: 'Hiring',
        title: 'How do you hire?',
        purpose:
            'Job postings run candidates through the stages of a hiring process.',
        href: '/setup/recruitment-pipelines',
    },
    {
        step: 'performance',
        icon: Target,
        label: 'Appraisals',
        title: 'How do you review your people?',
        purpose:
            'A framework decides what an appraisal measures, and how the result is reported.',
        href: '/setup/kpi',
    },
];

/** The step ladder, by key, for the screens that need one step's copy. */
export const STEP_META: Record<SetupStep, StepMeta> = Object.fromEntries(
    STEPS.map((meta) => [meta.step, meta]),
) as Record<SetupStep, StepMeta>;

/**
 * The Company Setup screens the wizard deliberately leaves out — real settings,
 * but none of them block day-one work. Named on the finish screen so the owner
 * knows what else is there rather than discovering it by accident.
 */
export const REMAINING_SETUP: { title: string; href: string; note: string }[] =
    [
        {
            title: 'Work schedule & holidays',
            href: '/setup/schedule',
            note: 'Other shifts and rotations, and the holiday calendar attendance and leave both read.',
        },
        {
            title: 'Onboarding programs',
            href: '/setup/onboarding',
            note: 'The checklist every new hire starts with.',
        },
        {
            title: 'Offboarding programs',
            href: '/setup/offboarding',
            note: 'The clearance checklist an exit runs through.',
        },
        {
            title: 'Award types',
            href: '/setup/award-types',
            note: 'The recognitions you give out.',
        },
    ];
