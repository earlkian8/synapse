/** Shapes for the home dashboard overview (see App\Queries\DashboardOverview). */

export type CompositionSlice = { type: string; label: string; count: number };
export type DeptCount = { name: string; count: number };

export type Workforce = {
    total: number;
    active: number;
    regular: number;
    probationary: number;
    on_leave: number;
    new_this_month: number;
    archived: number;
    departments: number;
    composition: CompositionSlice[];
    top_departments: DeptCount[];
};

export type TrendPoint = { label: string; value: number };

export type Attendance = {
    present: number;
    late: number;
    absent: number;
    on_leave: number;
    avg_hours: number;
    pending: number;
    workforce: number;
    trend: TrendPoint[];
};

export type Leave = {
    pending: number;
    on_leave_today: number;
    upcoming: number;
    days_this_month: number;
};

export type Recruitment = {
    open_postings: number;
    total_applicants: number;
    in_pipeline: number;
    offers: number;
    interviews_upcoming: number;
    hired_this_month: number;
};

export type Onboarding = {
    active: number;
    overdue_tasks: number;
    completing_soon: number;
    completed_this_month: number;
};

export type Offboarding = {
    active: number;
    flagged_items: number;
    leaving_soon: number;
    completed_this_month: number;
};

export type AttentionTone = 'amber' | 'rose' | 'teal';

export type AttentionItem = {
    key: string;
    label: string;
    count: number;
    href: string;
    tone: AttentionTone;
};

export type DashEvent = {
    id: number;
    title: string;
    type: string | null;
    starts_at: string | null;
    location: string | null;
};

export type ActivityItem = {
    id: number;
    description: string | null;
    event: string | null;
    subject_label: string | null;
    created_at: string | null;
    causer: { name: string; initials: string; avatar: string | null } | null;
};

export type DashboardProps = {
    today: string;
    workforce: Workforce | null;
    attendance: Attendance | null;
    leave: Leave | null;
    recruitment: Recruitment | null;
    onboarding: Onboarding | null;
    offboarding: Offboarding | null;
    attention: AttentionItem[];
    events: DashEvent[] | null;
    activity: ActivityItem[] | null;
};
