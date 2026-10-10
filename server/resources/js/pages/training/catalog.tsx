import { Head, Link, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    CalendarDays,
    CalendarRange,
    LibraryBig,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageBody, PageHeader, SearchInput } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { joinProgram } from '@/features/training/api';
import { TrainingNav } from '@/features/training/components/training-nav';
import {
    EnrollmentStatusBadge,
    ProgramStatusBadge,
} from '@/features/training/components/training-status-badge';
import { formatDateRange, validityLabel } from '@/features/training/constants';
import { trainingRoutes } from '@/features/training/routes';
import type {
    CatalogPageProps,
    CatalogProgram,
} from '@/features/training/types';

/**
 * Browse (ADR 0074): the programs the person may put themselves on — open ones
 * they join at once while seats last, and ones they ask to join and HR
 * approves. Each says when it runs, how many sessions it has, the seats left
 * and the certificate it earns, and where the person already stands on it.
 */
export default function TrainingCatalog() {
    const { programs, has_employee, nav } = usePage<CatalogPageProps>().props;

    const [search, setSearch] = useState('');
    const [joining, setJoining] = useState<CatalogProgram | null>(null);
    const [processing, setProcessing] = useState(false);

    const shown = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return needle === ''
            ? programs
            : programs.filter(
                  (p) =>
                      p.name.toLowerCase().includes(needle) ||
                      (p.provider ?? '').toLowerCase().includes(needle) ||
                      (p.description ?? '').toLowerCase().includes(needle),
              );
    }, [programs, search]);

    const join = () => {
        if (!joining) {
            return;
        }

        joinProgram(joining.hashid, {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setJoining(null);
            },
        });
    };

    const asking = joining?.enrollment_mode === 'approval';

    return (
        <>
            <Head title="Browse training" />

            <PageBody>
                <PageHeader
                    title="Browse"
                    description="Programs you can join yourself, or ask to join."
                    actions={<TrainingNav current="catalog" counts={nav} />}
                />

                {programs.length > 0 && (
                    <SearchInput
                        value={search}
                        onSearch={setSearch}
                        delay={0}
                        placeholder="Search programs…"
                        label="Search programs"
                        className="sm:w-72"
                    />
                )}

                {programs.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-14 text-center">
                        <LibraryBig
                            className="size-8 text-muted-foreground"
                            aria-hidden
                        />
                        <p className="font-medium">
                            Nothing open to join right now
                        </p>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            When HR opens a program to sign-ups, it shows here.
                            HR can still enroll you in others.
                        </p>
                    </div>
                ) : shown.length === 0 ? (
                    <p className="py-10 text-center text-sm text-muted-foreground">
                        No program matches “{search}”.
                    </p>
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {shown.map((program) => (
                            <ProgramCard
                                key={program.id}
                                program={program}
                                canJoin={has_employee}
                                onJoin={() => setJoining(program)}
                            />
                        ))}
                    </ul>
                )}
            </PageBody>

            <ConfirmDialog
                open={joining !== null}
                onOpenChange={(open) => !open && setJoining(null)}
                title={
                    asking
                        ? `Ask to join ${joining?.name ?? ''}?`
                        : `Join ${joining?.name ?? ''}?`
                }
                description={
                    asking
                        ? 'HR approves or declines your request, and you’re told either way.'
                        : 'You take a seat now. Its sessions appear on My training.'
                }
                confirmLabel={asking ? 'Ask to join' : 'Join'}
                processing={processing}
                onConfirm={join}
            />
        </>
    );
}

function ProgramCard({
    program,
    canJoin,
    onJoin,
}: {
    program: CatalogProgram;
    canJoin: boolean;
    onJoin: () => void;
}) {
    const full = program.enrollment_mode === 'open' && program.is_full;

    return (
        <li className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="font-semibold">{program.name}</h2>
                    <p className="text-xs text-muted-foreground">
                        {program.provider ?? 'In-house'}
                    </p>
                </div>
                <ProgramStatusBadge status={program.status} />
            </div>

            {program.description && (
                <p className="line-clamp-3 text-sm text-muted-foreground">
                    {program.description}
                </p>
            )}

            <ul className="flex flex-col gap-1 text-xs text-muted-foreground">
                <li className="inline-flex items-center gap-1.5">
                    <CalendarRange className="size-3.5" />
                    {formatDateRange(program.start_date, program.end_date)}
                </li>
                <li className="inline-flex items-center gap-1.5">
                    <CalendarDays className="size-3.5" />
                    {program.sessions_count === 0
                        ? 'Sessions not scheduled yet'
                        : `${program.sessions_count} ${program.sessions_count === 1 ? 'session' : 'sessions'}`}
                </li>
                <li className="inline-flex items-center gap-1.5">
                    <Users className="size-3.5" />
                    {program.seats_remaining === null
                        ? 'No seat limit'
                        : program.seats_remaining === 0
                          ? 'No seats left'
                          : `${program.seats_remaining} of ${program.capacity} seats left`}
                </li>
                {program.issues_certificate && (
                    <li className="inline-flex items-center gap-1.5 text-[#08767c] dark:text-[#0ABFBF]">
                        <BadgeCheck className="size-3.5" />
                        Certificate,{' '}
                        {validityLabel(program.certificate_validity_months)}
                    </li>
                )}
            </ul>

            <div className="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
                <Standing program={program} />
                {program.mine === null && canJoin && (
                    <Button size="sm" disabled={full} onClick={onJoin}>
                        {full
                            ? 'Full'
                            : program.enrollment_mode === 'approval'
                              ? 'Ask to join'
                              : 'Join'}
                    </Button>
                )}
            </div>
        </li>
    );
}

/** Where the person stands on a program, or how they would join it. */
function Standing({ program }: { program: CatalogProgram }) {
    const mine = program.mine;

    if (mine === null) {
        return (
            <span className="text-xs text-muted-foreground">
                {program.enrollment_mode === 'approval'
                    ? 'HR approves each request'
                    : 'Join at once'}
            </span>
        );
    }

    if (mine.status === 'declined') {
        return (
            <span className="flex flex-col gap-1 text-xs text-muted-foreground">
                <EnrollmentStatusBadge
                    status="declined"
                    className="self-start"
                />
                {mine.decision_note}
            </span>
        );
    }

    return (
        <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <EnrollmentStatusBadge status={mine.status} />
            <Link
                href={trainingRoutes.meTab('programs')}
                className="underline-offset-4 hover:text-foreground hover:underline"
            >
                {mine.status === 'requested'
                    ? 'Waiting for HR'
                    : 'On My training'}
            </Link>
        </span>
    );
}

TrainingCatalog.layout = {
    breadcrumbs: [
        { title: 'Training & Development', href: '/training' },
        { title: 'Browse', href: '/training/catalog' },
    ],
};
