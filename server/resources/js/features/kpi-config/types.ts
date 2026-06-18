import type { EvaluationPeriodOption } from '@/features/performance/types';

export type KpiCriterion = {
    id: number;
    hashid: string;
    name: string;
    description: string | null;
    weight: number;
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
