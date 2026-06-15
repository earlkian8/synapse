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
    | 'regular'
    | 'probationary'
    | 'contractual'
    | 'part_time';

export type PostingCard = {
    hashid: string;
    title: string;
    employment_type: EmploymentType;
    openings: number;
    closing_date: string | null;
    department: string | null;
    position: string | null;
    excerpt: string | null;
};

export type PostingDetail = PostingCard & {
    description: string | null;
    requirements: string | null;
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
