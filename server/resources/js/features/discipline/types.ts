/** Discipline (ADR 0105) — shaped as DisciplineScreens answers it. */
import type { PageMeta } from '@/components/data-table';

export type CaseStatus =
    | 'reported'
    | 'notice_issued'
    | 'explained'
    | 'hearing'
    | 'decided'
    | 'closed'
    | 'withdrawn';

export type Penalty =
    | 'verbal_warning'
    | 'written_warning'
    | 'final_warning'
    | 'suspension'
    | 'dismissal';

export type Gravity = 'minor' | 'less_serious' | 'serious' | 'grave';

export type CasePerson = {
    id: number;
    name: string;
    initials: string;
    photo: string | null;
    employee_no: string | null;
    department?: string | null;
    position?: string | null;
};

export type CaseSummary = {
    hashid: string;
    reference: string;
    status: CaseStatus;
    employee: CasePerson | null;
    offense: { code: string; title: string; gravity: Gravity } | null;
    incident_date: string;
    reply_due_on: string | null;
    reply_overdue: boolean;
    explained: boolean;
    hearing_at: string | null;
    finding: 'upheld' | 'cleared' | null;
    penalty: Penalty | null;
    decided_at: string | null;
};

export type CaseDetail = Omit<CaseSummary, 'offense'> & {
    offense: {
        id: number;
        code: string;
        title: string;
        gravity: Gravity;
        ground: string | null;
        just_cause: boolean;
    } | null;
    incident_time: string | null;
    location: string | null;
    narrative: string;
    notice_grounds: string | null;
    notice_issued_at: string | null;
    notice_received_at: string | null;
    preventive_from: string | null;
    preventive_to: string | null;
    explanation: string | null;
    explained_at: string | null;
    explanation_by_hr: boolean;
    hearing_requested: boolean;
    hearing_place: string | null;
    hearing_held_at: string | null;
    hearing_attended: boolean | null;
    hearing_notes: string | null;
    occurrence: number | null;
    penalty_label: string | null;
    suspension_days: number | null;
    penalty_from: string | null;
    penalty_to: string | null;
    decision: string | null;
    decision_received_at: string | null;
    withdrawn_reason: string | null;
    closed_at: string | null;
};

export type HrCase = CaseDetail & {
    witnesses: string | null;
    reported_by: string | null;
    notice_issued_by: string | null;
    decided_by: string | null;
    suggested_penalty: Penalty | null;
    attachments: {
        id: number;
        name: string;
        size: number;
        url: string | null;
        uploaded_by: string | null;
        uploaded_at: string | null;
    }[];
};

export type Standing = {
    key:
        | 'dismissed'
        | 'suspended'
        | 'preventive'
        | 'final_warning'
        | 'warned'
        | 'under_review'
        | 'good';
    label: string;
    until: string | null;
};

export type ConductRecord = {
    standing: Standing;
    active: {
        hashid: string;
        reference: string;
        offense: string | null;
        penalty: Penalty | null;
        penalty_label: string | null;
        decided_at: string | null;
        counts_until: string;
    }[];
    open: number;
    upheld: number;
    cleared: number;
};

export type Offense = {
    id: number;
    code: string;
    title: string;
    description: string | null;
    gravity: Gravity;
    ground: string;
    ground_label: string;
    just_cause: boolean;
    penalties: { action: Penalty; days?: number | null }[];
    reckoning_months: number;
    is_active: boolean;
    upheld_count: number;
};

export type ReportOptions = {
    employees: { id: number; name: string; employee_no: string | null }[];
    offenses: { id: number; code: string; title: string }[];
    today: string;
};

export type CasesPageProps = {
    cases: { data: CaseSummary[]; meta: PageMeta & { per_page: number } };
    filters: { view: 'open' | 'decided' | 'closed'; search: string };
    counts: Record<'open' | 'decided' | 'closed', number>;
    stats: {
        reported: number;
        awaiting: number;
        overdue: number;
        hearings: number;
    };
    options: ReportOptions;
    can: { manage: boolean; report: boolean };
};

export type CasePageProps = {
    case: HrCase;
    record: ConductRecord | null;
    suggestion: {
        occurrence: number;
        penalty: Penalty | null;
        days: number | null;
        prior: string[];
    };
    blockers: string[];
    options: {
        offenses: Offense[];
        penalties: Record<Penalty, string>;
        min_reply_days: number;
        earliest_reply: string;
        max_preventive_days: number;
        max_suspension_days: number;
    };
    today: string;
    can: { manage: boolean; own: boolean; offboard: boolean };
};
