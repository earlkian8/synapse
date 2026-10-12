import type { TimezoneOption } from '@/components/timezone-select';

export type CompanyProfile = {
    id: number;
    name: string;
    slug: string;
    legal_name: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    /** The IANA zone attendance is judged on, e.g. "Asia/Manila". */
    timezone: string;
    tin: string | null;
    sss_employer_no: string | null;
    philhealth_employer_no: string | null;
    pagibig_employer_no: string | null;
    /** Who signs the documents the system generates (ADR 0077). */
    document_signatory_name: string | null;
    document_signatory_title: string | null;
    logo: string | null;
    logo_url: string | null;
    initials: string;
    updated_human: string | null;
};

export type CompanyProfilePermissions = {
    manage: boolean;
};

export type CompanyProfilePageProps = {
    company: CompanyProfile;
    timezones: TimezoneOption[];
    can: CompanyProfilePermissions;
};
