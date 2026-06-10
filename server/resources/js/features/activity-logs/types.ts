export type ActivityCauser = {
    id: number;
    full_name: string;
    email: string;
    initials: string;
    avatar: string | null;
};

export type ActivityLogEntry = {
    id: number;
    log_name: string | null;
    event: string;
    description: string;
    causer: ActivityCauser | null;
    subject_type: string | null;
    subject_id: number | null;
    subject_label: string | null;
    properties: Record<string, unknown> | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string | null;
    created_human: string | null;
    created_display: string | null;
};

export type ActivityStats = {
    total: number;
    today: number;
    this_week: number;
    this_month: number;
    creates: number;
    deletions: number;
};

export type SortDirection = 'asc' | 'desc';

export type ActivityFilters = {
    search: string;
    event: string;
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

export type ActivityLogsPageProps = {
    logs: Paginated<ActivityLogEntry>;
    stats: ActivityStats;
    filters: ActivityFilters;
};
