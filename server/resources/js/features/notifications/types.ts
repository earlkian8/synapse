export type NotificationLevel = 'info' | 'success' | 'warning' | 'error';

export type AppNotification = {
    id: string;
    title: string;
    body: string;
    url: string | null;
    level: NotificationLevel;
    category: string;
    actor: string | null;
    read: boolean;
    created_at: string | null;
    created_human: string | null;
};

/** The compact payload shared on every page for the header bell. */
export type SharedNotifications = {
    items: AppNotification[];
    unread: number;
} | null;

export type NotificationStats = {
    total: number;
    unread: number;
};

export type NotificationPreferences = {
    email: boolean;
    push: boolean;
};

export type WebPushInfo = {
    vapidPublicKey: string | null;
    subscribed: boolean;
};

export type AudienceRole = {
    id: number;
    name: string;
    label: string;
};

export type AudienceUser = {
    id: number;
    name: string;
    email: string;
};

export type Audiences = {
    roles: AudienceRole[];
    users: AudienceUser[];
} | null;

export type NotificationsFilter = 'all' | 'unread';

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

export type NotificationsPageProps = {
    notifications: Paginated<AppNotification>;
    stats: NotificationStats;
    preferences: NotificationPreferences;
    webPush: WebPushInfo;
    canSend: boolean;
    audiences: Audiences;
    filters: { filter: NotificationsFilter };
};
