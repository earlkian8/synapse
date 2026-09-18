import type { TimezoneOption } from '@/components/timezone-select';
import type { PolicyPreset } from '@/features/attendance-policy-config/types';
import type { CompanyProfile } from '@/features/company-profile/types';
import type {
    BandTone,
    RatingBand,
    ResultDisplay,
} from '@/features/performance/types';
import type {
    StageDraft,
    StageKind,
} from '@/features/recruitment-pipelines/types';

/** The six things a brand-new company is walked through, in wizard order. */
export type SetupStep =
    | 'company'
    | 'departments'
    | 'leave-types'
    | 'attendance'
    | 'recruitment'
    | 'performance';

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

/** What the company already has, so a step can say so instead of assuming empty. */
export type ExistingConfiguration = {
    departments: string[];
    leaveTypes: string[];
    attendancePolicies: string[];
    schedules: string[];
    pipelines: string[];
    frameworks: string[];
};

export type SetupWizardPageProps = {
    company: CompanyProfile;
    /** Every zone step one can offer. */
    timezones: TimezoneOption[];
    progress: SetupProgress;
    blueprints: {
        departments: DepartmentBlueprint[];
        leaveTypes: LeaveTypeBlueprint[];
        /** How a day is judged — each preset with its complete settings (ADR 0038). */
        attendancePolicies: PolicyPreset[];
        pipelines: PipelineBlueprint[];
        frameworks: FrameworkBlueprint[];
        /** What a company designing its own framework draws on. */
        criteria: CriterionBlueprint[];
        instruments: InstrumentBlueprint[];
        bands: RatingBand[];
        tones: BandTone[];
    };
    existing: ExistingConfiguration;
    /** Per step, because the six steps are six different permissions. */
    can: Record<SetupStep, boolean>;
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
