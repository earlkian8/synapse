import { Head, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    SearchInput,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { EventFormSheet } from '@/features/events/components/event-form-sheet';
import { EventStatsCards } from '@/features/events/components/event-stats';
import { EventTable } from '@/features/events/components/event-table';
import type { EventSort } from '@/features/events/components/event-table';
import { EventsNav } from '@/features/events/components/events-nav';
import {
    STATUS_LABELS,
    STATUS_ORDER,
    TYPE_LABELS,
} from '@/features/events/constants';
import { eventRoutes } from '@/features/events/routes';
import type {
    EventIndexPageProps,
    EventItem,
    EventStatus,
    EventType,
} from '@/features/events/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

/** What the status filter can show: a lifecycle stage, or the archive. */
type StatusFilter = EventStatus | 'all' | 'archived';

/** Rank an event by its lifecycle for the table's status sort. */
const STATUS_RANK: Record<EventStatus, number> = {
    ongoing: 0,
    upcoming: 1,
    past: 2,
};

/**
 * Events & Meetings: one table of every event — filtered by type and status,
 * where the archive is one more status — opening onto each event's page.
 */
export default function EventsIndex() {
    const { events, archived, stats, can } =
        usePage<EventIndexPageProps>().props;

    const [search, setSearch] = useState('');
    const [type, setType] = useState<EventType | 'all'>('all');
    const [status, setStatus] = useState<StatusFilter>('all');
    const [sort, setSort] = useState<EventSort>('schedule');
    const [direction, setDirection] = useState<'asc' | 'desc'>('asc');

    const [form, setForm] = useState<{
        open: boolean;
        event: EventItem | null;
    }>({ open: false, event: null });
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const showingArchive = status === 'archived';

    const withProcessing = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirmOpen(false);
        },
    };

    const onSort = (key: EventSort) => {
        if (key === sort) {
            setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSort(key);
            setDirection('asc');
        }
    };

    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return (showingArchive ? archived : events).filter((event) => {
            if (type !== 'all' && event.type !== type) {
                return false;
            }

            if (
                !showingArchive &&
                status !== 'all' &&
                event.status !== status
            ) {
                return false;
            }

            return (
                needle === '' ||
                event.title.toLowerCase().includes(needle) ||
                (event.location ?? '').toLowerCase().includes(needle) ||
                (event.organizer?.name ?? '').toLowerCase().includes(needle)
            );
        });
    }, [events, archived, showingArchive, search, type, status]);

    const sorted = useMemo(() => {
        const dir = direction === 'asc' ? 1 : -1;
        const time = (iso: string | null) =>
            iso ? new Date(iso).getTime() : 0;

        return [...filtered].sort((a, b) => {
            switch (sort) {
                case 'title':
                    return a.title.localeCompare(b.title) * dir;
                case 'location':
                    return (
                        (a.room?.name ?? a.location ?? '').localeCompare(
                            b.room?.name ?? b.location ?? '',
                        ) * dir
                    );
                case 'attendance':
                    return (a.attending_count - b.attending_count) * dir;
                case 'status':
                    return (
                        (STATUS_RANK[a.status] - STATUS_RANK[b.status]) * dir
                    );
                default:
                    return (time(a.starts_at) - time(b.starts_at)) * dir;
            }
        });
    }, [filtered, sort, direction]);

    const page = useClientPagination(
        sorted,
        [search, type, status, sort, direction].join('|'),
    );

    const isFiltered = search !== '' || type !== 'all' || status !== 'all';

    const restore = (event: EventItem) =>
        router.patch(
            eventRoutes.restore(event.hashid),
            {},
            { preserveScroll: true },
        );

    const forceDelete = (event: EventItem) => {
        setConfirm({
            title: `Permanently delete "${event.title}"?`,
            description:
                'This cannot be undone. An event with attendees cannot be permanently deleted.',
            confirmLabel: 'Delete permanently',
            run: () =>
                router.delete(
                    eventRoutes.forceDelete(event.hashid),
                    withProcessing,
                ),
        });
        setConfirmOpen(true);
    };

    return (
        <>
            <Head title="Events & Meetings" />

            <PageBody>
                <PageHeader
                    title="Events & Meetings"
                    description="Company events and meetings, who's invited, and the rooms they hold."
                    actions={<EventsNav current="events" />}
                />

                <EventStatsCards stats={stats} />

                <div className="flex flex-col gap-3">
                    <ListToolbar
                        filtered={isFiltered}
                        onReset={() => {
                            setSearch('');
                            setType('all');
                            setStatus('all');
                        }}
                        actions={
                            can.manage && (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        setForm({ open: true, event: null })
                                    }
                                >
                                    <Plus className="size-4" />
                                    New event
                                </Button>
                            )
                        }
                    >
                        <SearchInput
                            value={search}
                            onSearch={setSearch}
                            delay={0}
                            placeholder="Search title, place, organizer…"
                            label="Search events"
                        />
                        <FilterSelect
                            label="Filter by type"
                            value={type}
                            onChange={(value) =>
                                setType(value as EventType | 'all')
                            }
                            options={[
                                { value: 'all', label: 'All types' },
                                ...(
                                    Object.keys(TYPE_LABELS) as EventType[]
                                ).map((t) => ({
                                    value: t,
                                    label: `${TYPE_LABELS[t]}s`,
                                })),
                            ]}
                            className="w-36"
                        />
                        <FilterSelect
                            label="Filter by status"
                            value={status}
                            onChange={(value) =>
                                setStatus(value as StatusFilter)
                            }
                            options={[
                                { value: 'all', label: 'All statuses' },
                                ...STATUS_ORDER.map((s) => ({
                                    value: s,
                                    label: STATUS_LABELS[s],
                                })),
                                ...(archived.length > 0
                                    ? [
                                          {
                                              value: 'archived',
                                              label: `Archived (${archived.length})`,
                                          },
                                      ]
                                    : []),
                            ]}
                            className="w-44"
                        />
                    </ListToolbar>

                    <EventTable
                        events={page.rows}
                        sort={sort}
                        direction={direction}
                        onSort={onSort}
                        canManage={can.manage}
                        archived={showingArchive}
                        filtered={isFiltered}
                        onRestore={restore}
                        onForceDelete={forceDelete}
                    />

                    <TablePagination
                        meta={page.meta}
                        perPage={page.perPage}
                        onPage={page.setPage}
                        onPerPage={page.setPerPage}
                    />
                </div>
            </PageBody>

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

EventsIndex.layout = {
    breadcrumbs: [{ title: 'Events & Meetings', href: '/events' }],
};
