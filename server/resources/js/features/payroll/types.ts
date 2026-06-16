export type PayrollStatus = 'draft' | 'processing' | 'finalized' | 'paid';

export type PayslipStatus = 'draft' | 'released';

export type PayslipEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    position: string | null;
    department: string | null;
};

export type PayslipLine = {
    id: number;
    label: string;
    amount: number;
    kind?: string | null;
};

export type Payslip = {
    id: number;
    hashid: string;
    status: PayslipStatus;
    basic_pay: number;
    overtime_pay: number;
    gross_pay: number;
    total_earnings: number;
    total_deductions: number;
    net_pay: number;
    days_worked: number;
    employee: PayslipEmployee | null;
    period?: {
        name: string;
        start_date: string;
        end_date: string;
        pay_date: string;
        status: PayrollStatus;
    } | null;
    earnings?: PayslipLine[];
    deductions?: PayslipLine[];
};

export type PayrollPeriod = {
    id: number;
    hashid: string;
    name: string;
    start_date: string;
    end_date: string;
    pay_date: string;
    status: PayrollStatus;
    processed_by: string | null;
    headcount: number;
    total_gross: number;
    total_net: number;
    total_deductions: number;
    payslips?: Payslip[];
};

export type PayrollStats = {
    net: number;
    deductions: number;
    headcount: number;
    pending: number;
};

export type PayrollPermissions = { process: boolean; release: boolean };

export type PayrollIndexPageProps = {
    periods: PayrollPeriod[];
    stats: PayrollStats;
    can: PayrollPermissions;
    filters: { status: string };
};

export type PayrollShowPageProps = {
    period: PayrollPeriod;
    can: PayrollPermissions;
};
