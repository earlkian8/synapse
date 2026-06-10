import { Head, router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck, Megaphone, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/features/notifications/components/confirm-dialog';
import { NotificationComposeSheet } from '@/features/notifications/components/notification-compose-sheet';
import { NotificationItem } from '@/features/notifications/components/notification-item';
import { NotificationPreferencesPanel } from '@/features/notifications/components/notification-preferences';
import { notificationRoutes } from '@/features/notifications/routes';
import type {
    AppNotification,
    NotificationsFilter,
    NotificationsPageProps,
} from '@/features/notifications/types';
import { cn } from '@/lib/utils';

export default function NotificationsIndex() {
    const {
        notifications,
        stats,
        preferences,
        webPush,
        canSend,
        audiences,
        filters,
    } = usePage<NotificationsPageProps>().props;

    const [composeOpen, setComposeOpen] = useState(false);
    const [clearOpen, setClearOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const setFilter = (filter: NotificationsFilter) =>
        router.get(
            notificationRoutes.index,
            filter === 'all' ? {} : { filter },
            { preserveScroll: true, preserveState: true },
        );

    const goToPage = (page: number) =>
        router.get(
            notificationRoutes.index,
            {
                ...(filters.filter !== 'all' ? { filter: filters.filter } : {}),
                page,
            },
            { preserveScroll: true, preserveState: true },
        );

    const opts = { preserveScroll: true, preserveState: true } as const;

    const markRead = (id: string) =>
        router.patch(notificationRoutes.read(id), {}, opts);

    const markAllRead = () => router.post(notificationRoutes.readAll, {}, opts);

    const removeOne = (id: string) =>
        router.delete(notificationRoutes.destroy(id), opts);

    const open = (notification: AppNotification) => {
        if (!notification.read) {
            markRead(notification.id);
        }

        if (notification.url) {
            router.visit(notification.url);
        }
    };

    const clearAll = () =>
        router.delete(notificationRoutes.clear, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setClearOpen(false);
            },
        });

    const meta = notifications.meta;

    return (
        <>
            <Head title="Notifications" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-5 p-4 md:p-6">
                {/* Heading */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Notifications
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Your alerts, announcements and account activity.
                        </p>
                    </div>
                    {canSend && (
                        <Button size="sm" onClick={() => setComposeOpen(true)}>
                            <Megaphone className="size-4" />
                            Send notification
                        </Button>
                    )}
                </div>

                {/* Preferences */}
                <NotificationPreferencesPanel
                    preferences={preferences}
                    webPush={webPush}
                />

                {/* Toolbar */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="inline-flex rounded-lg border border-border bg-muted/40 p-0.5">
                        <FilterTab
                            active={filters.filter === 'all'}
                            onClick={() => setFilter('all')}
                            label="All"
                            count={stats.total}
                        />
                        <FilterTab
                            active={filters.filter === 'unread'}
                            onClick={() => setFilter('unread')}
                            label="Unread"
                            count={stats.unread}
                        />
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={markAllRead}
                            disabled={stats.unread === 0}
                            className="text-muted-foreground"
                        >
                            <CheckCheck className="size-4" />
                            Mark all read
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setClearOpen(true)}
                            disabled={stats.total === 0}
                            className="text-muted-foreground hover:text-destructive"
                        >
                            <Trash2 className="size-4" />
                            Clear all
                        </Button>
                    </div>
                </div>

                {/* List */}
                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                    {notifications.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center gap-2 py-20 text-center">
                            <span className="flex size-12 items-center justify-center rounded-full bg-muted">
                                <Bell className="size-6 text-muted-foreground" />
                            </span>
                            <p className="text-sm font-medium">
                                {filters.filter === 'unread'
                                    ? 'No unread notifications'
                                    : 'No notifications yet'}
                            </p>
                            <p className="max-w-xs text-sm text-muted-foreground">
                                {filters.filter === 'unread'
                                    ? "You're all caught up."
                                    : 'Alerts and announcements will show up here.'}
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-border">
                            {notifications.data.map((notification) => (
                                <NotificationItem
                                    key={notification.id}
                                    notification={notification}
                                    onOpen={open}
                                    onRead={markRead}
                                    onDelete={removeOne}
                                />
                            ))}
                        </div>
                    )}
                </div>

                {/* Pagination */}
                {meta.last_page > 1 && (
                    <div className="flex items-center justify-between gap-2">
                        <p className="text-sm text-muted-foreground">
                            Page {meta.current_page} of {meta.last_page} ·{' '}
                            {meta.total} total
                        </p>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={meta.current_page <= 1}
                                onClick={() => goToPage(meta.current_page - 1)}
                            >
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={meta.current_page >= meta.last_page}
                                onClick={() => goToPage(meta.current_page + 1)}
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            {canSend && (
                <NotificationComposeSheet
                    audiences={audiences}
                    open={composeOpen}
                    onOpenChange={setComposeOpen}
                />
            )}

            <ConfirmDialog
                open={clearOpen}
                onOpenChange={setClearOpen}
                title="Clear all notifications?"
                description="This permanently removes every notification from your list. This cannot be undone."
                confirmLabel="Clear all"
                destructive
                processing={processing}
                onConfirm={clearAll}
            />
        </>
    );
}

function FilterTab({
    active,
    onClick,
    label,
    count,
}: {
    active: boolean;
    onClick: () => void;
    label: string;
    count: number;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                active
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
            )}
        >
            {label}
            <span
                className={cn(
                    'rounded-full px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                    active
                        ? 'bg-[#0ABFBF]/12 text-[#0a8a8a] dark:text-[#0ABFBF]'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                {count}
            </span>
        </button>
    );
}

NotificationsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Notifications',
            href: '/notifications',
        },
    ],
};
