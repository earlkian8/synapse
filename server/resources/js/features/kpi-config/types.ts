import type {
    BandTone,
    EvaluationPeriodOption,
    RatingBand,
    ReviewTemplateOption,
    ScaleLevel,
    ScaleType,
} from '@/features/performance/types';

/** A reusable measurement instrument the tenant defines once. */
export type RatingScaleOption = {
    id: number;
    hashid: string;
    name: string;
    description: string | null;
    type: ScaleType;
    min: number;
    max: number;
    step: number;
    levels: ScaleLevel[] | null;
    is_default: boolean;
    is_archived: boolean;
    /** "1–5", "0–100%", "5 levels" — the instrument in three words. */
    descriptor: string;
    usage_count: number;
};

/** A catalogue criterion: what is measured, and on which scale. */
export type KpiCriterion = {
    id: number;
    hashid: string;
    name: string;
    description: string | null;
    weight: number;
    rating_scale_id: number | null;
    scale_name: string | null;
    scale_descriptor: string | null;
    is_active: boolean;
    is_archived: boolean;
    usage_count: number;
};

/** The populations a framework's eligibility rule can name. */
export type AudienceOptions = Record<
    'department' | 'position' | 'employment_type',
    { value: string; label: string }[]
>;

export type KpiSetupPageProps = {
    templates: ReviewTemplateOption[];
    archivedTemplates: ReviewTemplateOption[];
    scales: RatingScaleOption[];
    archivedScales: RatingScaleOption[];
    criteria: KpiCriterion[];
    archivedCriteria: KpiCriterion[];
    periods: EvaluationPeriodOption[];
    archivedPeriods: EvaluationPeriodOption[];
    audiences: AudienceOptions;
    tones: BandTone[];
    defaultBands: RatingBand[];
    can: { manage: boolean };
};
