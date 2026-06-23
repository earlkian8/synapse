import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    CalendarClock,
    Plus,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { EventCard } from '@/features/events/components/event-card';
import { EventFormSheet } from '@/features/events/components/event-form-sheet';
import { EventStatsCards } from '@/features/events/components/event-stats';
import { STATUS_LABELS, STATUS_ORDER } from '@/features/events/constants';
import { eventRoutes } from '@/features/events/routes';
import type {
    EventIndexPageProps,
    EventItem,
    EventStatus,
} from '@/features/events/types';
import { ConfirmDialog } from '@/features/payroll/components/confirm-dialog';
import { cn } from '@/lib/utils';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

export default function EventsIndex() {
    const { events, archived, stats, can } =
        usePage<EventIndexPageProps>().props;

    const [form, setForm] = useState<{
        open: boolean;
        event: EventItem | null;
    }>({ open: false, event: null });
    const [showArchived, setShowArchived] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const withProcessing = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirmOpen(false);
        },
    };

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    // Group events by derived status, preserving the canonical order.
    const grouped = useMemo(() => {
        const map = new Map<EventStatus, EventItem[]>();

        for (const event of events) {
            const list = map.get(event.status) ?? [];
            list.push(event);
            map.set(event.status, list);
        }

        return STATUS_ORDER.filter((s) => map.has(s)).map((status) => ({
            status,
            items: map.get(status) as EventItem[],
        }));
    }, [events]);

    return (
        <>
            <Head title="Events & Meetings" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Events &amp; Meetings
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Company events and meetings, and who's invited.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {archived.length > 0 && (
                            <Button
                                variant={showArchived ? 'secondary' : 'ghost'}
                                size="sm"
                                onClick={() => setShowArchived((v) => !v)}
                                className={cn(
                                    !showArchived && 'text-muted-foreground',
                                )}
                            >
                                <Archive className="size-4" />
                                Archived
                                <span className="ml-0.5 tabular-nums">
                                    ({archived.length})
                                </span>
                            </Button>
                        )}
                        {can.manage && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    setForm({ open: true, event: null })
                                }
                            >
                                <Plus className="size-4" />
                                New event
                            </Button>
                        )}
                    </div>
                </div>

                <EventStatsCards stats={stats} />

                {events.length === 0 ? (
                    <EmptyState canManage={can.manage} />
                ) : (
                    <div className="flex flex-col gap-7">
                        {grouped.map(({ status, items }) => (
                            <section
                                key={status}
                                className="flex flex-col gap-3"
                            >
                                <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {STATUS_LABELS[status]}
                                    <span className="ml-1 tabular-nums">
                                        ({items.length})
                                    </span>
                                </h2>
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                    {items.map((event) => (
                                        <EventCard
                                            key={event.id}
                                            event={event}
                                        />
                                    ))}
                                </div>
                            </section>
                        ))}
                    </div>
                )}

                {showArchived && archived.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Archived events
                        </h2>
                        {archived.map((event) => (
                            <div
                                key={event.id}
                                className="flex items-center gap-3 rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-3 py-2.5 dark:border-sidebar-border"
                            >
                                <span className="min-w-0 flex-1 truncate text-sm font-medium text-muted-foreground">
                                    {event.title}
                                </span>
                                {can.manage && (
                                    <>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.patch(
                                                    eventRoutes.restore(
                                                        event.hashid,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <ArchiveRestore className="size-4" />
                                            Restore
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground hover:text-destructive"
                                            onClick={() =>
                                                askConfirm({
                                                    title: `Permanently delete "${event.title}"?`,
                                                    description:
                                                        'This cannot be undone. An event with attendees cannot be permanently deleted.',
                                                    confirmLabel:
                                                        'Delete permanently',
                                                    run: () =>
                                                        router.delete(
                                                            eventRoutes.forceDelete(
                                                                event.hashid,
                                                            ),
                                                            withProcessing,
                                                        ),
                                                })
                                            }
                                            aria-label="Delete permanently"
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <EventFormSheet
                event={form.event}
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
            />

            {confirm && (
                <ConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    title={confirm.title}
                    description={confirm.description}
                    confirmLabel={confirm.confirmLabel}
                    destructive
                    processing={processing}
                    onConfirm={confirm.run}
                />
            )}
        </>
    );
}

function EmptyState({ canManage }: { canManage: boolean }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <CalendarClock className="size-5" />
            </span>
            <p className="text-sm font-medium">No events scheduled yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                {canManage
                    ? 'Schedule one with "New event", then invite attendees to it.'
                    : 'No events or meetings have been scheduled yet.'}
            </p>
        </div>
    );
}

EventsIndex.layout = {
    breadcrumbs: [{ title: 'Events & Meetings', href: '/events' }],
};
