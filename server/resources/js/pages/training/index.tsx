import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    GraduationCap,
    Plus,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { ProgramCard } from '@/features/training/components/program-card';
import { ProgramFormSheet } from '@/features/training/components/program-form-sheet';
import { ProgramTable } from '@/features/training/components/program-table';
import type { ProgramSort } from '@/features/training/components/program-table';
import { TrainingStatsCards } from '@/features/training/components/training-stats';
import { TrainingToolbar } from '@/features/training/components/training-toolbar';
import {
    PROGRAM_STATUS_LABELS,
    PROGRAM_STATUS_ORDER,
} from '@/features/training/constants';
import { useProgramsView } from '@/features/training/hooks/use-programs-view';
import { trainingRoutes } from '@/features/training/routes';
import type {
    ProgramStatus,
    TrainingIndexPageProps,
    TrainingProgram,
} from '@/features/training/types';
import { cn } from '@/lib/utils';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

/** Rank a program by its lifecycle for the table's status sort. */
const STATUS_RANK: Record<ProgramStatus, number> = {
    ongoing: 0,
    upcoming: 1,
    completed: 2,
};

export default function TrainingIndex() {
    const { programs, archived, stats, can } =
        usePage<TrainingIndexPageProps>().props;

    const { view, changeView } = useProgramsView();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<ProgramStatus | 'all'>('all');
    const [sort, setSort] = useState<ProgramSort>('schedule');
    const [direction, setDirection] = useState<'asc' | 'desc'>('desc');

    const [form, setForm] = useState<{
        open: boolean;
        program: TrainingProgram | null;
    }>({ open: false, program: null });
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

    const onSort = (key: ProgramSort) => {
        if (key === sort) {
            setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSort(key);
            setDirection(key === 'name' || key === 'status' ? 'asc' : 'desc');
        }
    };

    // Search + status filter applied to both layouts.
    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return programs.filter((program) => {
            if (status !== 'all' && program.status !== status) {
                return false;
            }

            if (
                needle !== '' &&
                !program.name.toLowerCase().includes(needle) &&
                !(program.provider ?? '').toLowerCase().includes(needle)
            ) {
                return false;
            }

            return true;
        });
    }, [programs, search, status]);

    // Table layout: a flat, sorted list.
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
                    return (
                        (time(a.start_date) - time(b.start_date)) * dir
                    );
            }
        });
    }, [filtered, sort, direction]);

    // Grid layout: filtered programs grouped by derived status.
    const grouped = useMemo(() => {
        const map = new Map<ProgramStatus, TrainingProgram[]>();

        for (const program of filtered) {
            const list = map.get(program.status) ?? [];
            list.push(program);
            map.set(program.status, list);
        }

        return PROGRAM_STATUS_ORDER.filter((s) => map.has(s)).map((s) => ({
            status: s,
            items: map.get(s) as TrainingProgram[],
        }));
    }, [filtered]);

    return (
        <>
            <Head title="Training & Development" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Training &amp; Development
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            The organisation's training programs and who's
                            enrolled.
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
                                    setForm({ open: true, program: null })
                                }
                            >
                                <Plus className="size-4" />
                                New program
                            </Button>
                        )}
                    </div>
                </div>

                <TrainingStatsCards stats={stats} />

                {programs.length === 0 ? (
                    <EmptyState canManage={can.manage} />
                ) : (
                    <div className="flex flex-col gap-4">
                        <TrainingToolbar
                            search={search}
                            status={status}
                            view={view}
                            shown={filtered.length}
                            total={programs.length}
                            canExport
                            onSearch={setSearch}
                            onStatus={setStatus}
                            onView={changeView}
                        />

                        {filtered.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-10 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                                No programs match these filters.
                            </p>
                        ) : view === 'table' ? (
                            <ProgramTable
                                programs={sorted}
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                            />
                        ) : (
                            <div className="flex flex-col gap-7">
                                {grouped.map(({ status: s, items }) => (
                                    <section
                                        key={s}
                                        className="flex flex-col gap-3"
                                    >
                                        <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            {PROGRAM_STATUS_LABELS[s]}
                                            <span className="ml-1 tabular-nums">
                                                ({items.length})
                                            </span>
                                        </h2>
                                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                            {items.map((program) => (
                                                <ProgramCard
                                                    key={program.id}
                                                    program={program}
                                                />
                                            ))}
                                        </div>
                                    </section>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {showArchived && archived.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Archived programs
                        </h2>
                        {archived.map((program) => (
                            <div
                                key={program.id}
                                className="flex items-center gap-3 rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-3 py-2.5 dark:border-sidebar-border"
                            >
                                <span className="min-w-0 flex-1 truncate text-sm font-medium text-muted-foreground">
                                    {program.name}
                                </span>
                                {can.manage && (
                                    <>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.patch(
                                                    trainingRoutes.restore(
                                                        program.hashid,
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
                                                    title: `Permanently delete "${program.name}"?`,
                                                    description:
                                                        'This cannot be undone. A program with enrollments cannot be permanently deleted.',
                                                    confirmLabel:
                                                        'Delete permanently',
                                                    run: () =>
                                                        router.delete(
                                                            trainingRoutes.forceDelete(
                                                                program.hashid,
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

function EmptyState({ canManage }: { canManage: boolean }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <GraduationCap className="size-5" />
            </span>
            <p className="text-sm font-medium">No training programs yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                {canManage
                    ? 'Create a program with "New program", then enroll employees into it.'
                    : 'No training programs have been set up yet.'}
            </p>
        </div>
    );
}

TrainingIndex.layout = {
    breadcrumbs: [{ title: 'Training', href: '/training' }],
};
