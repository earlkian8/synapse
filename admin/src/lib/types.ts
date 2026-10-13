/** Shapes the portal API answers with (server: app/Http/Resources/Admin). */

export type AdminRole = 'super_admin' | 'admin';

export type Admin = {
    id: number;
    name: string;
    email: string;
    role: AdminRole;
    role_label: string;
    is_active: boolean;
    two_factor_enabled: boolean;
    must_change_password: boolean;
    last_login_at: string | null;
    last_login_ip: string | null;
    password_changed_at: string | null;
    created_at: string | null;
    created_by?: string | null;
    is_self: boolean;
};

export type PageMeta = {
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
};

export type ListFilters = {
    search: string;
    status: string;
    sort: string;
    direction: 'asc' | 'desc';
    per_page: number;
};

/** A page of a list, with the list's own counts and the filters as applied. */
export type Paginated<T, Stats, Filters extends ListFilters = ListFilters> = {
    data: T[];
    meta: PageMeta;
    stats: Stats;
    filters: Filters;
};

export type Option = { value: string; label: string };
