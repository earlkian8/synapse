export type TrashTypeKey = 'user' | 'employee' | 'department' | 'leave_type';

export type TrashAvatar = {
    initials: string;
    photo: string | null;
};

export type TrashItem = {
    type: TrashTypeKey;
    type_label: string;
    id: number;
    title: string;
    subtitle: string | null;
    avatar: TrashAvatar | null;
    deleted_at: string | null;
    deleted_human: string | null;
    can_restore: boolean;
    can_force_delete: boolean;
};

export type TrashTypeSummary = {
    key: TrashTypeKey;
    label: string;
    plural: string;
    icon: string;
    count: number;
    can_restore: boolean;
    can_force_delete: boolean;
};

export type TrashSummary = {
    total: number;
    types: TrashTypeSummary[];
};

export type TrashFilters = {
    search: string;
    type: string;
    per_page: number;
};

export type TrashRef = {
    type: TrashTypeKey;
    id: number;
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

export type TrashPageProps = {
    items: Paginated<TrashItem>;
    summary: TrashSummary;
    filters: TrashFilters;
};
