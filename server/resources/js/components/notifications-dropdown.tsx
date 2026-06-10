import { router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    DEFAULT_LEVEL_STYLE,
    LEVEL_STYLES,
} from '@/features/notifications/constants';
import { notificationRoutes } from '@/features/notifications/routes';
import type { AppNotification } from '@/features/notifications/types';
import { cn } from '@/lib/utils';

/** Refresh the shared bell payload on an interval so it stays close to live. */
const POLL_INTERVAL_MS = 30_000;

export function NotificationsDropdown() {
    const { notifications } = usePage().props;
    const items = notifications?.items ?? [];
    const unreadCount = notifications?.unread ?? 0;

    useEffect(() => {
        const id = window.setInterval(() => {
            router.reload({ only: ['notifications'] });
        }, POLL_INTERVAL_MS);

        return () => window.clearInterval(id);
    }, []);

    const markAllRead = () =>
        router.post(
            notificationRoutes.readAll,
            {},
            { preserveScroll: true, preserveState: true, only: ['notifications'] },
        );

    const openNotification = (notification: AppNotification) => {
        if (!notification.read) {
            router.patch(
                notificationRoutes.read(notification.id),
                {},
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['notifications'],
                },
            );
        }

        if (notification.url) {
            router.visit(notification.url);
        }
    };

    return (
        <DropdownMenu>
            <Tooltip>
                <TooltipTrigger asChild>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Notifications"
                            className="relative size-8 text-muted-foreground hover:text-foreground"
                        >
                            <Bell className="size-[18px]" />
                            {unreadCount > 0 && (
                                <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full border-2 border-background bg-[#0ABFBF] px-1 text-[9px] leading-none font-bold text-[#0F2044]">
                                    {unreadCount > 9 ? '9+' : unreadCount}
                                </span>
                            )}
                        </Button>
                    </DropdownMenuTrigger>
                </TooltipTrigger>
                <TooltipContent side="bottom" className="text-xs">
                    Notifications
                </TooltipContent>
            </Tooltip>

            <DropdownMenuContent
                align="end"
                sideOffset={8}
                className="w-[360px] overflow-hidden p-0"
            >
                {/* Header */}
                <div className="flex items-center justify-between border-b px-4 py-3">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold">
                            Notifications
                        </span>
                        {unreadCount > 0 && (
                            <span className="rounded-full bg-[#0ABFBF]/12 px-1.5 py-0.5 text-[10px] font-semibold text-[#0a8a8a] dark:text-[#0ABFBF]">
                                {unreadCount} new
                            </span>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={markAllRead}
                        disabled={unreadCount === 0}
                        className="flex items-center gap-1 text-[11px] font-medium text-muted-foreground transition-colors hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
                    >
                        <CheckCheck className="size-3.5" />
                        Mark all read
                    </button>
                </div>

                {/* List */}
                <div className="max-h-[340px] overflow-y-auto">
                    {items.length === 0 ? (
                        <div className="flex flex-col items-center justify-center gap-2 px-4 py-10 text-center">
                            <span className="flex size-10 items-center justify-center rounded-full bg-muted">
                                <Bell className="size-5 text-muted-foreground" />
                            </span>
                            <p className="text-[13px] font-medium">
                                You're all caught up
                            </p>
                            <p className="text-[12px] text-muted-foreground">
                                New notifications will appear here.
                            </p>
                        </div>
                    ) : (
                        items.map((notification) => {
                            const style =
                                LEVEL_STYLES[notification.level] ??
                                DEFAULT_LEVEL_STYLE;
                            const Icon = style.icon;

                            return (
                                <button
                                    key={notification.id}
                                    type="button"
                                    onClick={() =>
                                        openNotification(notification)
                                    }
                                    className={cn(
                                        'relative flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-accent',
                                        !notification.read &&
                                            'bg-[#0ABFBF]/[0.04]',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg',
                                            style.chip,
                                        )}
                                    >
                                        <Icon className="size-4" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-[13px] leading-snug font-medium">
                                            {notification.title}
                                        </p>
                                        <p className="mt-0.5 line-clamp-2 text-[12px] leading-snug text-muted-foreground">
                                            {notification.body}
                                        </p>
                                        <p className="mt-1 text-[11px] text-muted-foreground/70">
                                            {notification.created_human ?? ''}
                                        </p>
                                    </div>
                                    {!notification.read && (
                                        <span
                                            className={cn(
                                                'mt-1.5 size-2 shrink-0 rounded-full',
                                                style.accent,
                                            )}
                                        />
                                    )}
                                </button>
                            );
                        })
                    )}
                </div>

                {/* Footer */}
                <div className="border-t p-1.5">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.visit(notificationRoutes.index)}
                        className="w-full justify-center text-[12px] font-medium text-muted-foreground hover:text-foreground"
                    >
                        View all notifications
                    </Button>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
