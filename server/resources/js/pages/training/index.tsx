import { Head, router, usePage } from '@inertiajs/react';
import { Download, Plus } from 'lucide-react';
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
import { ProgramFormSheet } from '@/features/training/components/program-form-sheet';
import { ProgramTable } from '@/features/training/components/program-table';
import type { ProgramSort } from '@/features/training/components/program-table';
import { TrainingStatsCards } from '@/features/training/components/training-stats';
import {
    PROGRAM_STATUS_LABELS,
    PROGRAM_STATUS_ORDER,
} from '@/features/training/constants';
import { trainingRoutes } from '@/features/training/routes';
import type {
    ProgramStatus,
    TrainingIndexPageProps,
    TrainingProgram,
} from '@/features/training/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

/** What the status filter can show: a lifecycle stage, or the archive. */
type StatusFilter = ProgramStatus | 'all' | 'archived';

/** Rank a program by its lifecycle for the table's status sort. */
const STATUS_RANK: Record<ProgramStatus, number> = {
    ongoing: 0,
    upcoming: 1,
    completed: 2,
};

/**
 * Training & Development: one table of every program — filtered by status,
 * where the archive is one more status — opening onto each program's roster.
 */
export default function TrainingIndex() {
    const { programs, archived, stats, can } =
        usePage<TrainingIndexPageProps>().props;

    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<StatusFilter>('all');
    const [sort, setSort] = useState<ProgramSort>('schedule');
    const [direction, setDirection] = useState<'asc' | 'desc'>('desc');

    const [form, setForm] = useState<{
        open: boolean;
        program: TrainingProgram | null;
    }>({ open: false, program: null });
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

    const onSort = (key: ProgramSort) => {
        if (key === sort) {
            setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSort(key);
            setDirection(key === 'name' || key === 'status' ? 'asc' : 'desc');
        }
    };

    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return (showingArchive ? archived : programs).filter((program) => {
            if (
                !showingArchive &&
                status !== 'all' &&
                program.status !== status
            ) {
                return false;
            }

            return (
                needle === '' ||
                program.name.toLowerCase().includes(needle) ||
                (program.provider ?? '').toLowerCase().includes(needle)
            );
        });
    }, [programs, archived, showingArchive, search, status]);

    const sorted = useMemo(() => {
        const dir = direction === 'asc' ? 1 : -1;
        const time = (iso: string | null) =>
            iso ? new Date(iso).getTime() : 0;

        return [...filtered].sort((a, b) => {
            switch (sort) {
                case 'name':
                    return a.name.localeCompare(b.name) * dir;
                case 'seats':
                    return (a.active_count - b.active_count) * dir;
                case 'completed':
                    return (a.completed_count - b.completed_count) * dir;
                case 'status':
                    return (
                        (STATUS_RANK[a.status] - STATUS_RANK[b.status]) * dir
                    );
                default:
                    return (time(a.start_date) - time(b.start_date)) * dir;
            }
        });
    }, [filtered, sort, direction]);

    const page = useClientPagination(
        sorted,
        [search, status, sort, direction].join('|'),
    );

    const isFiltered = search !== '' || status !== 'all';

    const restore = (program: TrainingProgram) =>
        router.patch(
            trainingRoutes.restore(program.hashid),
            {},
            { preserveScroll: true },
        );

    const forceDelete = (program: TrainingProgram) => {
        setConfirm({
            title: `Permanently delete "${program.name}"?`,
            description:
                'This cannot be undone. A program with enrollments cannot be permanently deleted.',
            confirmLabel: 'Delete permanently',
            run: () =>
                router.delete(
                    trainingRoutes.forceDelete(program.hashid),
                    withProcessing,
                ),
        });
        setConfirmOpen(true);
    };

    return (
        <>
            <Head title="Training & Development" />

            <PageBody>
                <PageHeader
                    title="Training & Development"
                    description="The organisation's training programs and who's enrolled."
                />

                <TrainingStatsCards stats={stats} />

                <div className="flex flex-col gap-3">
                    <ListToolbar
                        filtered={isFiltered}
                        onReset={() => {
                            setSearch('');
                            setStatus('all');
                        }}
                        actions={
                            <>
                                {programs.length > 0 && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href={trainingRoutes.export}>
                                            <Download className="size-4" />
                                            Export
                                        </a>
                                    </Button>
                                )}
                                {can.manage && (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            setForm({
                                                open: true,
                                                program: null,
                                            })
                                        }
                                    >
                                        <Plus className="size-4" />
                                        New program
                                    </Button>
                                )}
                            </>
                        }
                    >
                        <SearchInput
                            value={search}
                            onSearch={setSearch}
                            delay={0}
                            placeholder="Search program or provider…"
                            label="Search training programs"
                        />
                        <FilterSelect
                            label="Filter by status"
                            value={status}
                            onChange={(value) =>
                                setStatus(value as StatusFilter)
                            }
                            options={[
                                { value: 'all', label: 'All statuses' },
                                ...PROGRAM_STATUS_ORDER.map((s) => ({
                                    value: s,
                                    label: PROGRAM_STATUS_LABELS[s],
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

                    <ProgramTable
                        programs={page.rows}
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

            <ProgramFormSheet
                program={form.program}
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

TrainingIndex.layout = {
    breadcrumbs: [{ title: 'Training', href: '/training' }],
};
