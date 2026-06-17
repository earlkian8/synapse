export type BenefitCategory =
    | 'hmo'
    | 'insurance'
    | 'retirement'
    | 'wellness'
    | 'other';

export type BenefitFrequency = 'monthly' | 'quarterly' | 'annual' | 'one_time';

export type EnrollmentStatus = 'active' | 'pending' | 'waived' | 'terminated';

export type EnrollmentEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    position: string | null;
    department: string | null;
};

export type BenefitEnrollment = {
    id: number;
    status: EnrollmentStatus;
    reference_no: string | null;
    enrolled_on: string | null;
    ended_on: string | null;
    notes: string | null;
    employee?: EnrollmentEmployee | null;
    plan?: {
        id: number;
        hashid: string;
        name: string;
        category: BenefitCategory;
        provider: string | null;
        employee_cost: number;
        employer_cost: number;
        frequency: BenefitFrequency;
    } | null;
};

export type BenefitPlan = {
    id: number;
    hashid: string;
    name: string;
    category: BenefitCategory;
    provider: string | null;
    description: string | null;
    employee_cost: number;
    employer_cost: number;
    total_cost: number;
    frequency: BenefitFrequency;
    is_active: boolean;
    is_archived: boolean;
    monthly_employee_cost: number;
    monthly_employer_cost: number;
    enrollments_count: number;
    active_count: number;
    enrollments?: BenefitEnrollment[];
};

export type BenefitStats = {
    plans: number;
    enrolled: number;
    monthly_employer: number;
    monthly_employee: number;
};

export type BenefitPermissions = { manage: boolean };

export type EnrollableEmployee = {
    id: number;
    full_name: string;
    employee_no: string;
};

export type BenefitsIndexPageProps = {
    plans: BenefitPlan[];
    stats: BenefitStats;
    can: BenefitPermissions;
};

export type BenefitsShowPageProps = {
    plan: BenefitPlan;
    enrollable: EnrollableEmployee[];
    can: BenefitPermissions;
};
