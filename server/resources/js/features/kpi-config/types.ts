import type { EvaluationPeriodOption } from '@/features/performance/types';

/** How a criterion is rated: an N-point scale, a percentage, or named levels. */
export type ScaleType = 'points' | 'percentage' | 'scale';

/** One named level of a descriptive rating scale. */
export type ScaleLevel = { label: string; value: number };

export type KpiCriterion = {
    id: number;
    hashid: string;
    name: string;
    description: string | null;
    weight: number;
    scale_type: ScaleType;
    scale_min: number;
    scale_max: number;
    scale_levels: ScaleLevel[] | null;
    is_active: boolean;
    is_archived: boolean;
    usage_count: number;
};

export type KpiSetupPageProps = {
    criteria: KpiCriterion[];
    archivedCriteria: KpiCriterion[];
    periods: EvaluationPeriodOption[];
    archivedPeriods: EvaluationPeriodOption[];
    can: { manage: boolean };
};
