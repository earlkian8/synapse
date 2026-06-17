export type AllowanceType = {
    id: number;
    hashid: string;
    name: string;
    is_taxable: boolean;
    usage_count: number;
    is_archived: boolean;
};

export type DeductionKind =
    | 'sss'
    | 'philhealth'
    | 'pagibig'
    | 'withholding_tax'
    | 'loan'
    | 'other';

export type DeductionType = {
    id: number;
    hashid: string;
    name: string;
    kind: DeductionKind;
    is_mandatory: boolean;
    computation_type: string;
    rate: number | null;
    cap: number | null;
    usage_count: number;
    is_archived: boolean;
};

export const DEDUCTION_KIND_LABELS: Record<DeductionKind, string> = {
    sss: 'SSS',
    philhealth: 'PhilHealth',
    pagibig: 'Pag-IBIG',
    withholding_tax: 'Withholding Tax',
    loan: 'Loan',
    other: 'Other',
};

export type PayrollSetupPageProps = {
    allowanceTypes: AllowanceType[];
    archivedAllowances: AllowanceType[];
    deductionTypes: DeductionType[];
    archivedDeductions: DeductionType[];
    can: { manage: boolean };
};
