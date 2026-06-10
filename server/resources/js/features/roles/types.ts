export type ManagedRole = {
    id: number;
    name: string;
    label: string;
    description: string | null;
    is_system: boolean;
    is_super_admin: boolean;
    permissions: string[];
    permissions_count: number;
    users_count: number;
    created_at: string | null;
    created_human: string | null;
    updated_at: string | null;
};

export type RoleStats = {
    total: number;
    system: number;
    custom: number;
    permissions: number;
    assigned_users: number;
    unassigned_users: number;
};

export type PermissionOption = {
    name: string;
    label: string;
};

export type PermissionGroup = {
    group: string;
    permissions: PermissionOption[];
};

export type BulkRoleAction = 'delete';

export type SortDirection = 'asc' | 'desc';

export type RolesFilters = {
    search: string;
    type: string;
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

export type RolesPageProps = {
    roles: Paginated<ManagedRole>;
    stats: RoleStats;
    permissionGroups: PermissionGroup[];
    filters: RolesFilters;
};
