export type AwardEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    position: string | null;
    department: string | null;
};

export type EmployeeAward = {
    id: number;
    awarded_on: string | null;
    reason: string | null;
    employee?: AwardEmployee | null;
    award_type?: {
        id: number;
        hashid: string;
        name: string;
        color: string | null;
    } | null;
    granted_by?: { id: number; name: string } | null;
};

export type AwardType = {
    id: number;
    hashid: string;
    name: string;
    description: string | null;
    color: string | null;
    is_active: boolean;
    is_archived: boolean;
    awards_count: number;
};

export type AwardTypeOption = {
    id: number;
    name: string;
    color: string | null;
};

export type AwardableEmployee = {
    id: number;
    full_name: string;
    employee_no: string;
};

export type AwardStats = {
    total: number;
    this_month: number;
    recognized: number;
    types: number;
};

export type AwardPermissions = { manage: boolean };

export type AwardsIndexPageProps = {
    awards: EmployeeAward[];
    types: AwardTypeOption[];
    employees: AwardableEmployee[];
    stats: AwardStats;
    can: AwardPermissions;
};
