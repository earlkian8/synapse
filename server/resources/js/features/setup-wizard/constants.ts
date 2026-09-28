import {
    Award,
    Building2,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    Clock,
    DoorOpen,
    ListChecks,
    MapPinned,
    Network,
    Target,
    Workflow,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { SetupStep, StepGroup } from './types';

type StepMeta = {
    step: SetupStep;
    group: StepGroup;
    icon: LucideIcon;
    /** The short name in the step ladder. */
    label: string;
    /** The heading of the step itself. */
    title: string;
    /** One line saying what this step is for — and what depends on it. */
    purpose: string;
    /** Where the same thing is configured afterwards, under Company Setup. */
    href: string;
};

/** The three stretches of the road, in the order the rail and the welcome show them. */
export const GROUPS: { group: StepGroup; label: string }[] = [
    { group: 'company', label: 'Your company' },
    { group: 'time', label: 'Time & attendance' },
    { group: 'people', label: 'Your people, hire to exit' },
];

/**
 * Every Company Setup screen as a step, in the order the server walks them
 * (`CompanySetup::STEPS`). Where one step's options come from an earlier one —
 * a site's default schedule, a roster's schedules — the earlier one comes
 * first.
 */
export const STEPS: StepMeta[] = [
    {
        step: 'company',
        group: 'company',
        icon: Building2,
        label: 'Company',
        title: 'Tell us about your company',
        purpose:
            'The name, logo and details that appear on documents, job posts and payroll remittances.',
        href: '/setup/company',
    },
    {
        step: 'departments',
        group: 'company',
        icon: Network,
        label: 'Departments',
        title: 'How is your company organised?',
        purpose:
            'Every employee, job posting and appraisal is filed under a department and a position.',
        href: '/setup/departments',
    },
    {
        step: 'attendance',
        group: 'time',
        icon: Clock,
        label: 'Attendance rules',
        title: 'How are your days judged?',
        purpose:
            'When someone is late or short, what counts as overtime, and the hours most people work.',
        href: '/setup/attendance-policies',
    },
    {
        step: 'schedule',
        group: 'time',
        icon: CalendarDays,
        label: 'Schedules & holidays',
        title: 'When do people work, and when is everyone off?',
        purpose:
            'The shifts people are put on, and the holidays that are never charged as leave.',
        href: '/setup/schedule',
    },
    {
        step: 'leave-types',
        group: 'time',
        icon: CalendarRange,
        label: 'Leave',
        title: 'What leave do you grant?',
        purpose: 'Employees can only file the kinds of leave you define here.',
        href: '/setup/leave-types',
    },
    {
        step: 'locations',
        group: 'time',
        icon: MapPinned,
        label: 'Locations',
        title: 'Where do your people work?',
        purpose:
            'Each site is a fence on the map that web and app punches are placed against.',
        href: '/setup/locations',
    },
    {
        step: 'roster',
        group: 'time',
        icon: CalendarClock,
        label: 'Shift roster',
        title: 'Who works which shift?',
        purpose:
            'The plan each day of attendance is judged against — who is due in, and when.',
        href: '/setup/roster',
    },
    {
        step: 'recruitment',
        group: 'people',
        icon: Workflow,
        label: 'Hiring',
        title: 'How do you hire?',
        purpose:
            'Job postings run candidates through the stages of a hiring process.',
        href: '/setup/recruitment-pipelines',
    },
    {
        step: 'onboarding',
        group: 'people',
        icon: ListChecks,
        label: 'Onboarding',
        title: 'How does a new hire start?',
        purpose:
            'The checklist every new hire is given the moment they are hired.',
        href: '/setup/onboarding',
    },
    {
        step: 'performance',
        group: 'people',
        icon: Target,
        label: 'Appraisals',
        title: 'How do you review your people?',
        purpose:
            'A framework decides what an appraisal measures; a review cycle is when it happens.',
        href: '/setup/kpi',
    },
    {
        step: 'awards',
        group: 'people',
        icon: Award,
        label: 'Awards',
        title: 'How do you recognise good work?',
        purpose:
            'The awards your people can be given, and what each one means.',
        href: '/setup/award-types',
    },
    {
        step: 'offboarding',
        group: 'people',
        icon: DoorOpen,
        label: 'Offboarding',
        title: 'How does someone leave well?',
        purpose:
            'The clearance every exit runs through, each item routed to the department that signs it off.',
        href: '/setup/offboarding',
    },
];

/** The step ladder, by key, for the screens that need one step's copy. */
export const STEP_META: Record<SetupStep, StepMeta> = Object.fromEntries(
    STEPS.map((meta) => [meta.step, meta]),
) as Record<SetupStep, StepMeta>;

/** The group label a step sits under. */
export const GROUP_LABEL: Record<StepGroup, string> = Object.fromEntries(
    GROUPS.map(({ group, label }) => [group, label]),
) as Record<StepGroup, string>;
