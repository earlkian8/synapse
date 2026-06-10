export type UserStatus = 'active' | 'inactive' | 'archived';

export type UserRole = {
    id: number;
    name: string;
    label: string;
};

export type ManagedUser = {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    suffix: string | null;
    full_name: string;
    initials: string;
    email: string;
    phone_number: string | null;
    profile_photo: string | null;
    employee_id: string | null;
    roles: UserRole[];
    is_active: boolean;
    status: UserStatus;
    email_verified: boolean;
    two_factor_enabled: boolean;
    has_password: boolean;
    last_login_at: string | null;
    last_login_human: string | null;
    password_changed_at: string | null;
    created_at: string | null;
    created_human: string | null;
    updated_at: string | null;
    deleted_at: string | null;
};

export type UserStats = {
    total: number;
    active: number;
    inactive: number;
    unverified: number;
    new_this_month: number;
    archived: number;
};

export type SortDirection = 'asc' | 'desc';

export type UsersFilters = {
    search: string;
    status: string;
    sort: string;
    direction: SortDirection;
    per_page: number;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type PaginationMeta = {
    current_page: number;
    from: number | null;
    to: number | null;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
};

export type Paginated<T> = {
    data: T[];
    meta: PaginationMeta;
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
};

export type UserPermissions = {
    create: boolean;
    update: boolean;
    delete: boolean;
    restore: boolean;
    forceDelete: boolean;
    manageStatus: boolean;
    resetPassword: boolean;
    export: boolean;
    assignRoles: boolean;
};

export type UsersPageProps = {
    users: Paginated<ManagedUser>;
    stats: UserStats;
    assignableRoles: UserRole[];
    can: UserPermissions;
    filters: UsersFilters;
};

export type BulkAction =
    | 'activate'
    | 'deactivate'
    | 'archive'
    | 'restore'
    | 'delete';
