import {
    CalendarClock,
    Fingerprint,
    Globe,
    Monitor,
    Target,
    UserRound,
} from 'lucide-react';
import { DetailGroup, DetailRow } from '@/components/detail-list';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatDateTime, formatRelative } from '@/lib/format';
import type { ActivityEntry } from '../api';
import { EventPill } from './event-pill';

/** One log entry in full: who, what, on what, from where. */
export function ActivitySheet({
    entry,
    open,
    onClose,
}: {
    entry: ActivityEntry | null;
    open: boolean;
    onClose: () => void;
}) {
    return (
        <Sheet open={open} onOpenChange={(next) => !next && onClose()}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="sr-only">
                    <SheetTitle>Log entry</SheetTitle>
                    <SheetDescription>
                        Who did what, on what, and from where.
                    </SheetDescription>
                </SheetHeader>

                {entry && (
                    <>
                        <div className="border-b border-border bg-muted/30 px-6 py-6">
                            <EventPill
                                event={entry.event}
                                label={entry.event_label}
                            />
                            <h2 className="mt-3 text-base leading-snug font-semibold">
                                {entry.description}
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {formatDateTime(entry.created_at)}
                            </p>
                        </div>

                        <div className="space-y-6 px-6 py-6">
                            <DetailGroup title="Who">
                                <DetailRow
                                    icon={UserRound}
                                    label="Admin"
                                    value={
                                        entry.actor?.name ??
                                        (entry.actor
                                            ? 'No admin has this email'
                                            : 'Server console')
                                    }
                                />
                                <DetailRow
                                    icon={Fingerprint}
                                    label="Email"
                                    value={entry.actor?.email}
                                />
                            </DetailGroup>

                            <DetailGroup title="What">
                                <DetailRow
                                    icon={Target}
                                    label="Target"
                                    value={
                                        entry.subject_label ??
                                        (entry.subject_type
                                            ? `${entry.subject_type} #${entry.subject_id}`
                                            : null)
                                    }
                                />
                                <DetailRow
                                    icon={CalendarClock}
                                    label="When"
                                    value={formatRelative(entry.created_at)}
                                    title={formatDateTime(entry.created_at)}
                                />
                            </DetailGroup>

                            <DetailGroup title="From">
                                <DetailRow
                                    icon={Globe}
                                    label="IP address"
                                    value={
                                        entry.ip_address ? (
                                            <span className="font-mono text-xs">
                                                {entry.ip_address}
                                            </span>
                                        ) : null
                                    }
                                />
                                <div className="rounded-lg px-2 py-2">
                                    <span className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Monitor className="size-4" />
                                        Browser
                                    </span>
                                    <p className="mt-1 text-xs leading-relaxed break-words text-muted-foreground">
                                        {entry.user_agent ?? '—'}
                                    </p>
                                </div>
                            </DetailGroup>

                            {entry.properties &&
                                Object.keys(entry.properties).length > 0 && (
                                    <DetailGroup title="Details">
                                        <pre className="overflow-x-auto rounded-lg border border-sidebar-border/70 bg-muted/40 p-3 font-mono text-xs leading-relaxed">
                                            {JSON.stringify(
                                                entry.properties,
                                                null,
                                                2,
                                            )}
                                        </pre>
                                    </DetailGroup>
                                )}
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
