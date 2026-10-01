export type LeaveType = {
    id: number;
    hashid: string;
    name: string;
    code: string;
    description: string | null;
    color: string;
    default_days: number;
    is_paid: boolean;
    allow_half_day: boolean;
    requires_approval: boolean;
    is_active: boolean;
    is_archived: boolean;
    requests_count?: number;
    created_human: string | null;
    deleted_at: string | null;
};

export type LeaveTypesPageProps = {
    types: LeaveType[];
    archived: LeaveType[];
    can: { manage: boolean };
};
