import { AtSign, Clock, Globe, Hash, Monitor, Target } from 'lucide-react';
import type { ComponentType } from 'react';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { ActivityLogEntry } from '../types';
import { ActivityEventBadge } from './activity-event-badge';
import { ActorAvatar } from './actor-cell';

type Props = {
    log: ActivityLogEntry | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function formatValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.join(', ');
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

export function ActivityDetailSheet({ log, open, onOpenChange }: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="sr-only">
                    <SheetTitle>Activity log details</SheetTitle>
                </SheetHeader>

                {log && (
                    <>
                        <div className="border-b border-border bg-muted/30 px-6 py-6">
                            <div className="flex items-start gap-4">
                                <ActorAvatar
                                    causer={log.causer}
                                    className="size-14"
                                />
                                <div className="min-w-0 flex-1">
                                    <h2 className="truncate text-lg font-semibold">
                                        {log.causer?.full_name ?? 'System'}
                                    </h2>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {log.causer?.email ?? 'Automated action'}
                                    </p>
                                    <div className="mt-2">
                                        <ActivityEventBadge event={log.event} />
                                    </div>
                                </div>
                            </div>
                            <p className="mt-4 text-sm">{log.description}</p>
                        </div>

                        <div className="space-y-6 px-6 py-6">
                            <Group title="Target">
                                <Row
                                    icon={Target}
                                    label="Subject"
                                    value={
                                        log.subject_label ??
                                        (log.subject_type
                                            ? `${log.subject_type} #${log.subject_id}`
                                            : null)
                                    }
                                />
                                {log.subject_type && (
                                    <Row
                                        icon={Hash}
                                        label="Type"
                                        value={`${log.subject_type}${
                                            log.subject_id ? ` #${log.subject_id}` : ''
                                        }`}
                                    />
                                )}
                            </Group>

                            {log.properties &&
                                Object.keys(log.properties).length > 0 && (
                                    <Group title="Details">
                                        {Object.entries(log.properties).map(
                                            ([key, value]) => (
                                                <Row
                                                    key={key}
                                                    icon={Hash}
                                                    label={key.replace(/_/g, ' ')}
                                                    value={formatValue(value)}
                                                />
                                            ),
                                        )}
                                    </Group>
                                )}

                            <Group title="Request">
                                <Row
                                    icon={Globe}
                                    label="IP address"
                                    value={log.ip_address}
                                />
                                <Row
                                    icon={Monitor}
                                    label="User agent"
                                    value={log.user_agent}
                                />
                                <Row
                                    icon={AtSign}
                                    label="Category"
                                    value={log.log_name}
                                />
                            </Group>

                            <Group title="When">
                                <Row
                                    icon={Clock}
                                    label="Timestamp"
                                    value={log.created_display}
                                />
                                <Row
                                    icon={Clock}
                                    label="Relative"
                                    value={log.created_human}
                                />
                            </Group>
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

function Group({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h3>
            <div className="space-y-1">{children}</div>
        </div>
    );
}

function Row({
    icon: Icon,
    label,
    value,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value?: string | null;
}) {
    return (
        <div className="flex items-start justify-between gap-3 rounded-lg px-2 py-2 hover:bg-muted/40">
            <span className="flex items-center gap-2 text-sm text-muted-foreground capitalize">
                <Icon className="size-4 shrink-0" />
                {label}
            </span>
            <span className="max-w-[60%] truncate text-right text-sm font-medium">
                {value || '—'}
            </span>
        </div>
    );
}
