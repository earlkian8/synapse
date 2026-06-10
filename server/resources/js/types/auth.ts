export type User = {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    suffix: string | null;
    full_name: string;
    email: string;
    phone_number: string | null;
    profile_photo: string | null;
    employee_id: string | null;
    is_active: boolean;
    last_login_at: string | null;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    roles: string[];
    permissions: string[];
    is_super_admin: boolean;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
