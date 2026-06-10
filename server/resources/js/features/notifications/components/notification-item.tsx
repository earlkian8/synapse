import { Check, ExternalLink, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { DEFAULT_LEVEL_STYLE, LEVEL_STYLES } from '../constants';
import type { AppNotification } from '../types';

type Props = {
    notification: AppNotification;
    onOpen: (notification: AppNotification) => void;
    onRead: (id: string) => void;
    onDelete: (id: string) => void;
};

export function NotificationItem({
    notification,
    onOpen,
    onRead,
    onDelete,
}: Props) {
    const style = LEVEL_STYLES[notification.level] ?? DEFAULT_LEVEL_STYLE;
    const Icon = style.icon;

    return (
        <div
            className={cn(
                'group relative flex items-start gap-3 px-4 py-3.5 transition-colors hover:bg-accent/60',
                !notification.read && 'bg-[#0ABFBF]/[0.04]',
            )}
        >
            {/* Unread accent rail */}
            {!notification.read && (
                <span
                    className={cn(
                        'absolute top-0 left-0 h-full w-0.5',
                        style.accent,
                    )}
                />
            )}

            <span
                className={cn(
                    'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg',
                    style.chip,
                )}
            >
                <Icon className="size-4" />
            </span>

            <button
                type="button"
                onClick={() => onOpen(notification)}
                className="min-w-0 flex-1 text-left"
            >
                <div className="flex items-center gap-2">
                    <p className="truncate text-sm font-medium">
                        {notification.title}
                    </p>
                    {notification.url && (
                        <ExternalLink className="size-3 shrink-0 text-muted-foreground" />
                    )}
                </div>
                <p className="mt-0.5 line-clamp-2 text-[13px] leading-snug text-muted-foreground">
                    {notification.body}
                </p>
                <p className="mt-1 text-[11px] text-muted-foreground/70">
                    {notification.actor ? `${notification.actor} · ` : ''}
                    {notification.created_human ?? ''}
                </p>
            </button>

            {/* Row actions */}
            <div className="flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100">
                {!notification.read && (
                    <button
                        type="button"
                        onClick={() => onRead(notification.id)}
                        title="Mark as read"
                        aria-label="Mark as read"
                        className="rounded-md p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                    >
                        <Check className="size-4" />
                    </button>
                )}
                <button
                    type="button"
                    onClick={() => onDelete(notification.id)}
                    title="Delete"
                    aria-label="Delete notification"
                    className="rounded-md p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                >
                    <Trash2 className="size-4" />
                </button>
            </div>
        </div>
    );
}
