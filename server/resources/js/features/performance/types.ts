export type EvaluationStatus = 'draft' | 'submitted' | 'acknowledged';

export type PeriodStatus = 'draft' | 'open' | 'closed';

export type EvaluationEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    position: string | null;
    department: string | null;
};

export type EvaluationPeriodRef = {
    id: number;
    hashid: string;
    name: string;
    status: PeriodStatus;
    start_date: string | null;
    end_date: string | null;
};

export type PerformanceScore = {
    id: number;
    label: string;
    weight: number;
    score: number | null;
    remarks: string | null;
    // Whether the source criterion still exists (null when not loaded).
    criterion_active: boolean | null;
};

export type PerformanceEvaluation = {
    id: number;
    hashid: string;
    status: EvaluationStatus;
    overall_score: number | null;
    submitted_at: string | null;
    acknowledged_at: string | null;
    remarks: string | null;
    scores_count: number;
    employee?: EvaluationEmployee | null;
    period?: EvaluationPeriodRef | null;
    evaluator?: { id: number; name: string } | null;
    scores?: PerformanceScore[];
};

export type EvaluationPeriodOption = {
    id: number;
    hashid: string;
    name: string;
    start_date: string | null;
    end_date: string | null;
    status: PeriodStatus;
    is_archived: boolean;
    evaluations_count: number;
};

export type PerformanceEmployee = {
    id: number;
    full_name: string;
    employee_no: string;
};

export type PerformanceStats = {
    total: number;
    draft: number;
    submitted: number;
    acknowledged: number;
    average_score: number | null;
};

export type PerformancePermissions = { manage: boolean };

export type PerformanceIndexPageProps = {
    evaluations: PerformanceEvaluation[];
    periods: EvaluationPeriodOption[];
    employees: PerformanceEmployee[];
    stats: PerformanceStats;
    can: PerformancePermissions;
};

export type PerformanceShowPageProps = {
    evaluation: PerformanceEvaluation;
    can: PerformancePermissions;
};
