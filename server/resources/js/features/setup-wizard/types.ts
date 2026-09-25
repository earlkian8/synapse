import type { TimezoneOption } from '@/components/timezone-select';
import type { AttendancePoliciesPageProps } from '@/features/attendance-policy-config/types';
import type { PolicyPreset } from '@/features/attendance-policy-config/types';
import type { AwardTypeSetupPageProps } from '@/features/award-types-config/types';
import type { CompanyProfile } from '@/features/company-profile/types';
import type { DepartmentsPageProps } from '@/features/departments/types';
import type { DevicesPageProps } from '@/features/devices/types';
import type { KpiSetupPageProps } from '@/features/kpi-config/types';
import type { LeaveTypesPageProps } from '@/features/leave-types/types';
import type { LocationsPageProps } from '@/features/locations/types';
import type { ProgramsPageProps as OffboardingProgramsPageProps } from '@/features/offboarding/types';
import type { ProgramsPageProps as OnboardingProgramsPageProps } from '@/features/onboarding/types';
import type {
    BandTone,
    RatingBand,
    ResultDisplay,
} from '@/features/performance/types';
import type {
    PipelinesPageProps,
    StageDraft,
    StageKind,
} from '@/features/recruitment-pipelines/types';
import type { RosterPageProps } from '@/features/roster/types';
import type {
    HolidayType,
    ScheduleSetupPageProps,
} from '@/features/schedule-config/types';

/**
 * Every Company Setup screen, as a step, in wizard order — the company and its
 * shape, how its time is kept, then a person's life at the company from hire to
 * exit. Mirrors `CompanySetup::STEPS`.
 */
export type SetupStep =
    | 'company'
    | 'departments'
    | 'attendance'
    | 'schedule'
    | 'leave-types'
    | 'locations'
    | 'devices'
    | 'roster'
    | 'recruitment'
    | 'onboarding'
    | 'performance'
    | 'awards'
    | 'offboarding';

/** What the working pane is showing: the welcome, one step, or the send-off. */
export type WizardView = 'intro' | SetupStep | 'done';

/** The rail's three stretches of the road, each a run of steps. */
export type StepGroup = 'company' | 'time' | 'people';

/** What the company did with a step. A skip is an answer, not an absence. */
export type StepStatus = 'done' | 'skipped' | 'pending';

/** A suggested department. `code` is what the server resolves it by. */
export type DepartmentBlueprint = {
    code: string;
    name: string;
    description: string;
};

/** A suggested kind of leave, with the entitlement it normally carries. */
export type LeaveTypeBlueprint = {
    code: string;
    name: string;
    description: string;
    color: string;
    default_days: number;
    is_paid: boolean;
    allow_half_day: boolean;
    requires_approval: boolean;
    /** Pre-ticked in the wizard — the set almost every company needs. */
    recommended: boolean;
};

/**
 * A holiday on the Philippine calendar, on its next date. `key` is what the
 * server resolves it — and its date — by.
 */
export type HolidayBlueprint = {
    key: string;
    name: string;
    date: string;
    type: HolidayType;
    /** A fixed date, kept every year; a movable one is only its next date. */
    is_recurring: boolean;
};

/** A recognition most companies give out. */
export type AwardTypeBlueprint = {
    key: string;
    name: string;
    description: string;
    color: string;
};

/** An onboarding checklist to start from. */
export type OnboardingProgramBlueprint = {
    key: string;
    name: string;
    description: string;
    tasks: { title: string; category: string; due_offset_days: number }[];
};

/**
 * An exit clearance to start from. An item's `department` is a code — the
 * department it is routed to — or `__own__`, the leaver's own department.
 */
export type OffboardingProgramBlueprint = {
    key: string;
    name: string;
    description: string;
    items: { item: string; department: string }[];
};

/** A hiring process to start from (ADR 0029). */
export type PipelineBlueprint = {
    key: string;
    name: string;
    description: string;
    stages: { name: string; kind: StageKind }[];
};

/** One weighted part of an appraisal blueprint. */
export type FrameworkSectionBlueprint = {
    key: string;
    name: string;
    description: string;
    weight: number;
};

/** One thing a blueprint measures, resolved to the criterion behind it. */
export type FrameworkItemBlueprint = {
    /** The catalogue key, so opening a blueprint up keeps its lines linked. */
    criterion: string;
    section: string;
    weight: number;
    name: string;
    description: string;
    /** The instrument it is measured on, e.g. "Competency level". */
    scale: string;
};

/** A criterion the company can measure, out of the shared catalogue. */
export type CriterionBlueprint = {
    key: string;
    name: string;
    description: string;
    weight: number;
    scale: string;
};

/** An instrument a criterion can be measured on, e.g. "5-point rating". */
export type InstrumentBlueprint = {
    name: string;
    description: string;
    type: string;
    /** "1–5", "0–100%", "5 levels" — the instrument in three words. */
    descriptor: string;
};

/** An appraisal framework to start from (ADR 0028). */
export type FrameworkBlueprint = {
    key: string;
    name: string;
    description: string;
    scale: string;
    result_display: string;
    sections: FrameworkSectionBlueprint[];
    items: FrameworkItemBlueprint[];
};

export type SetupProgress = {
    steps: Record<SetupStep, StepStatus>;
    /** The step the wizard opens on — the first one still unanswered. */
    resume: SetupStep;
    completed: boolean;
};

/** What the company already has by name, so an offer it took reads "Already added". */
export type ExistingConfiguration = {
    departments: string[];
    leaveTypes: string[];
    attendancePolicies: string[];
    schedules: string[];
    holidays: string[];
    pipelines: string[];
    onboardingPrograms: string[];
    frameworks: string[];
    awardTypes: string[];
    offboardingPrograms: string[];
};

/** The company step's screen: the profile, the clock choices, the join code. */
export type CompanyScreen = {
    company: CompanyProfile;
    timezones: TimezoneOption[];
    /** Only for somebody who may rotate it — it is a credential. */
    joinCode: { code: string | null; enabled: boolean } | null;
    can: { manage: boolean };
};

/**
 * Each step's screen — exactly the props its Company Setup page renders with,
 * because the step renders the same editors (`CompanySetup::SCREENS`).
 */
export type StepScreens = {
    company: CompanyScreen;
    departments: DepartmentsPageProps;
    attendance: AttendancePoliciesPageProps;
    schedule: ScheduleSetupPageProps;
    'leave-types': LeaveTypesPageProps;
    locations: LocationsPageProps;
    devices: DevicesPageProps;
    roster: RosterPageProps;
    recruitment: PipelinesPageProps;
    onboarding: OnboardingProgramsPageProps;
    performance: KpiSetupPageProps;
    awards: AwardTypeSetupPageProps;
    offboarding: OffboardingProgramsPageProps;
};

export type SetupWizardPageProps = {
    view: WizardView;
    company: CompanyProfile;
    progress: SetupProgress;
    /** Whether each step's module already holds something to continue with. */
    configured: Record<SetupStep, boolean>;
    /** The step on show's Company Setup screen; null off a step, or without access. */
    screen: StepScreens[SetupStep] | null;
    blueprints: {
        departments: DepartmentBlueprint[];
        leaveTypes: LeaveTypeBlueprint[];
        /** How a day is judged — each preset with its complete settings (ADR 0038). */
        attendancePolicies: PolicyPreset[];
        holidays: HolidayBlueprint[];
        pipelines: PipelineBlueprint[];
        onboardingPrograms: OnboardingProgramBlueprint[];
        frameworks: FrameworkBlueprint[];
        awardTypes: AwardTypeBlueprint[];
        offboardingPrograms: OffboardingProgramBlueprint[];
        /** What a company designing its own framework draws on. */
        criteria: CriterionBlueprint[];
        instruments: InstrumentBlueprint[];
        bands: RatingBand[];
        tones: BandTone[];
    };
    existing: ExistingConfiguration;
    /** Per step, because every step is a different module's permission. */
    can: Record<SetupStep, boolean>;
    /** Whether the send-off can point at inviting people. */
    canInvite: boolean;
};

/**
 * What every step is handed by the page: whether its module holds anything yet,
 * and the ways on, back and past it.
 */
export type StepControls = {
    /** The step's module already holds something to continue with. */
    configured: boolean;
    /** Move on, recording the step as done (it must be configured). */
    onContinue: () => void;
    /** Move on after the step's own save has recorded it. */
    onNext: () => void;
    onBack: () => void;
    onSkip: () => void;
    /** Something whole-wizard is in flight; every button waits. */
    busy: boolean;
    /** …and it is this step's skip. */
    skipping: boolean;
    /** …and it is this step's continue. */
    advancing: boolean;
};

/*
| Drafts — a company's own definitions while they are being written.
|
| Everything below is client state, not server data: it exists between the
| moment somebody decides the offer on screen is not quite their company and the
| moment the step is saved. Each carries a `source` where it matters, which is
| the wizard's memory of the suggestion it started from — a customised offer
| stops being on offer, and putting the draft back restores it.
*/

/** A department the company described itself. */
export type DepartmentDraft = {
    name: string;
    code: string;
    description: string;
    /** The blueprint code this started as, or null when written from nothing. */
    source: string | null;
};

/** A kind of leave the company described itself, with its whole policy. */
export type LeaveTypeDraft = {
    name: string;
    code: string;
    description: string;
    color: string;
    default_days: string;
    is_paid: boolean;
    allow_half_day: boolean;
    requires_approval: boolean;
    source: string | null;
};

/** One weighted part of a framework the company is designing. */
export type SectionDraft = {
    key: string;
    name: string;
    description: string;
    weight: number;
};

/**
 * One thing that framework measures. `criterion` is the whole story of where the
 * line comes from: a catalogue key means it asks the question the catalogue's
 * way, and null means the company wrote it — in which case its own name,
 * meaning and instrument are what get saved.
 */
export type FrameworkItemDraft = {
    /** Positional rows need a stable identity of their own. */
    id: string;
    section: string;
    weight: number;
    criterion: string | null;
    name: string;
    description: string;
    scale: string;
};

/** An appraisal framework being designed in the wizard. */
export type FrameworkDraft = {
    name: string;
    description: string;
    scale: string;
    result_display: ResultDisplay;
    sections: SectionDraft[];
    items: FrameworkItemDraft[];
    bands: RatingBand[];
};

/** A hiring process being drawn in the wizard. */
export type PipelineDraft = {
    name: string;
    stages: StageDraft[];
};
