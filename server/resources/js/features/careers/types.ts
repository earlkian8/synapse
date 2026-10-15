/**
 * Types for the public (unauthenticated) careers surface — the org board, the
 * job page, and the application form. Mirrors the payloads built by
 * App\Http\Controllers\Public\CareersController.
 */
export type CareersOrg = {
    name: string;
    slug: string;
    logo_url: string | null;
    initials: string;
};

export type EmploymentType =
    'regular' | 'probationary' | 'contractual' | 'part_time';

export type SalaryPeriod = 'monthly' | 'annual' | 'daily' | 'hourly';

export type WorkArrangement = 'onsite' | 'hybrid' | 'remote';

export const WORK_ARRANGEMENT_LABELS: Record<WorkArrangement, string> = {
    onsite: 'On-site',
    hybrid: 'Hybrid',
    remote: 'Remote',
};

export type PostingCard = {
    hashid: string;
    title: string;
    employment_type: EmploymentType;
    openings: number;
    closing_date: string | null;
    department: string | null;
    position: string | null;
    work_arrangement: WorkArrangement;
    /** The site the role is based at. */
    location: string | null;
    /** The pay range, when the employer shows it ("₱25,000–₱35,000 a month"). */
    pay: string | null;
    excerpt: string | null;
};

export type ScreeningQuestion = { id: number; label: string };

export type PostingDetail = PostingCard & {
    description: string | null;
    requirements: string | null;
    requires_resume: boolean;
    address: string | null;
    min_years_experience: number | null;
    /** Whether the form asks for an expected salary, and per what. */
    asks_expected_salary: boolean;
    salary_period: SalaryPeriod;
    screening_questions: ScreeningQuestion[];
};

export type DocumentTypeOption = { value: string; label: string };

export const EMPLOYMENT_TYPE_LABELS: Record<EmploymentType, string> = {
    regular: 'Regular',
    probationary: 'Probationary',
    contractual: 'Contractual',
    part_time: 'Part-time',
};

export type CareersBoardProps = {
    organization: CareersOrg;
    postings: PostingCard[];
};

export type CareersShowProps = {
    organization: CareersOrg;
    posting: PostingDetail;
    documentTypes: DocumentTypeOption[];
    /** Flashed true immediately after a successful submission. */
    applied?: boolean;
};

/** "Hybrid · Makati office", "Remote", "On-site". */
export function placeLabel(posting: PostingCard): string {
    const arrangement = WORK_ARRANGEMENT_LABELS[posting.work_arrangement];

    return posting.location && posting.work_arrangement !== 'remote'
        ? `${arrangement} · ${posting.location}`
        : arrangement;
}
