import {
    Bell,
    CalendarClock,
    CheckCheck,
    FileCheck2,
    Settings2,
    UserPlus,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { cn } from '@/lib/utils';

type NotificationTone = 'teal' | 'amber' | 'violet' | 'slate';

type Notification = {
    id: string;
    icon: LucideIcon;
    tone: NotificationTone;
    title: string;
    description: string;
    time: string;
    read: boolean;
};

const toneStyles: Record<NotificationTone, string> = {
    teal: 'bg-[#0ABFBF]/12 text-[#0a8a8a] dark:text-[#0ABFBF]',
    amber: 'bg-amber-500/12 text-amber-600 dark:text-amber-400',
    violet: 'bg-violet-500/12 text-violet-600 dark:text-violet-400',
    slate: 'bg-slate-500/12 text-slate-600 dark:text-slate-300',
};

const initialNotifications: Notification[] = [
    {
        id: '1',
        icon: FileCheck2,
        tone: 'teal',
        title: 'Leave request awaiting approval',
        description: 'Maria Santos requested 3 days of vacation leave.',
        time: '5m ago',
        read: false,
    },
    {
        id: '2',
        icon: UserPlus,
        tone: 'violet',
        title: 'New applicant shortlisted',
        description: 'AI ranked 4 candidates for Senior Accountant.',
        time: '1h ago',
        read: false,
    },
    {
        id: '3',
        icon: CalendarClock,
        tone: 'amber',
        title: 'Payroll cut-off tomorrow',
        description: 'Finalize timesheets before 5:00 PM, June 10.',
        time: '3h ago',
        read: false,
    },
    {
        id: '4',
        icon: Settings2,
        tone: 'slate',
        title: 'Policy update published',
        description: 'The 2026 leave policy is now in effect.',
        time: 'Yesterday',
        read: true,
    },
];

export function NotificationsDropdown() {
    const [notifications, setNotifications] = useState(initialNotifications);

    const unreadCount = useMemo(
        () => notifications.filter((n) => !n.read).length,
        [notifications],
    );

    const markAllRead = () =>
        setNotifications((prev) => prev.map((n) => ({ ...n, read: true })));

    const markRead = (id: string) =>
        setNotifications((prev) =>
            prev.map((n) => (n.id === id ? { ...n, read: true } : n)),
        );

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
                                    {unreadCount}
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
                    {notifications.map((n) => {
                        const Icon = n.icon;

                        return (
                            <button
                                key={n.id}
                                type="button"
                                onClick={() => markRead(n.id)}
                                className={cn(
                                    'relative flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-accent',
                                    !n.read && 'bg-[#0ABFBF]/[0.04]',
                                )}
                            >
                                <span
                                    className={cn(
                                        'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg',
                                        toneStyles[n.tone],
                                    )}
                                >
                                    <Icon className="size-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[13px] leading-snug font-medium">
                                        {n.title}
                                    </p>
                                    <p className="mt-0.5 line-clamp-2 text-[12px] leading-snug text-muted-foreground">
                                        {n.description}
                                    </p>
                                    <p className="mt-1 text-[11px] text-muted-foreground/70">
                                        {n.time}
                                    </p>
                                </div>
                                {!n.read && (
                                    <span className="mt-1.5 size-2 shrink-0 rounded-full bg-[#0ABFBF]" />
                                )}
                            </button>
                        );
                    })}
                </div>

                {/* Footer */}
                <div className="border-t p-1.5">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="w-full justify-center text-[12px] font-medium text-muted-foreground hover:text-foreground"
                    >
                        View all notifications
                    </Button>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
