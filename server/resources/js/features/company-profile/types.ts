export type CompanyProfile = {
    id: number;
    name: string;
    slug: string;
    legal_name: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    tin: string | null;
    sss_employer_no: string | null;
    philhealth_employer_no: string | null;
    pagibig_employer_no: string | null;
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
    can: CompanyProfilePermissions;
};
