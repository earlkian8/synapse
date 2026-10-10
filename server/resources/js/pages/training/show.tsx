import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    BadgeCheck,
    CalendarCheck2,
    CircleCheckBig,
    GraduationCap,
    Pencil,
    TrendingUp,
    TriangleAlert,
    Users,
    UserX,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    HeaderIcon,
    PageBody,
    PageHeader,
    StatTiles,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { removeEnrollment } from '@/features/training/api';
import { BulkEnrollDialog } from '@/features/training/components/bulk-enroll-dialog';
import { CostsPanel } from '@/features/training/components/costs-panel';
import { EditEnrollmentDialog } from '@/features/training/components/edit-enrollment-dialog';
import { ProgramFormModal } from '@/features/training/components/program-form-modal';
import { RequestsCard } from '@/features/training/components/requests-card';
import { RosterTable } from '@/features/training/components/roster-table';
import { SessionsPanel } from '@/features/training/components/sessions-panel';
import { TrainingInsightsPanel } from '@/features/training/components/training-insights';
import { ProgramStatusBadge } from '@/features/training/components/training-status-badge';
import {
    ENROLLMENT_MODE_LABELS,
    formatDateRange,
    formatMoney,
    scoreTone,
    validityLabel,
} from '@/features/training/constants';
import { trainingRoutes } from '@/features/training/routes';
import type {
    TrainingEnrollment,
    TrainingShowPageProps,
} from '@/features/training/types';
import { cn } from '@/lib/utils';

type Tab = 'roster' | 'sessions' | 'costs';

const TABS: Tab[] = ['roster', 'sessions', 'costs'];

/**
 * One training program: its headline figures, then underline tabs — the roster
 * (with the requests to join that wait), its sessions and the attendance taken
 * at them, and its costs against its budget (ADRs 0074, 0075). `?tab=` opens a
 * tab directly.
 */
export default function TrainingShow() {
    const page = usePage<TrainingShowPageProps>();
    const {
        program,
        requests,
        sessions,
        attendance,
        costs,
        money,
        enrollable,
        analytics,
        ai_available,
        can,
    } = page.props;
    const enrollments = program.enrollments ?? [];

    const asked = new URLSearchParams(page.url.split('?')[1] ?? '').get('tab');
    const [tab, setTab] = useState<Tab>(
        TABS.includes(asked as Tab) ? (asked as Tab) : 'roster',
    );

    const [enrollOpen, setEnrollOpen] = useState(false);
    const [edit, setEdit] = useState<TrainingEnrollment | null>(null);
    const [editProgram, setEditProgram] = useState(false);
    const [remove, setRemove] = useState<TrainingEnrollment | null>(null);
    const [archiveOpen, setArchiveOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const confirmRemove = () => {
        if (!remove) {
            return;
        }

        removeEnrollment(remove.id, {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setRemove(null);
            },
        });
    };

    const archive = () =>
        router.delete(trainingRoutes.destroy(program.hashid), {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setArchiveOpen(false);
            },
        });

    const rate = analytics.completion_rate;

    return (
        <>
            <Head title={`Training — ${program.name}`} />

            <PageBody>
                <PageHeader
                    back={{
                        href: trainingRoutes.index,
                        label: 'Back to all programs',
                    }}
                    leading={
                        <HeaderIcon>
                            <GraduationCap />
                        </HeaderIcon>
                    }
                    title={program.name}
                    badges={<ProgramStatusBadge status={program.status} />}
                    description={
                        <>
                            {program.provider ?? 'In-house'} ·{' '}
                            {formatDateRange(
                                program.start_date,
                                program.end_date,
                            )}
                            <span className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                <span>
                                    {
                                        ENROLLMENT_MODE_LABELS[
                                            program.enrollment_mode
                                        ]
                                    }
                                </span>
                                {program.issues_certificate && (
                                    <span className="inline-flex items-center gap-1">
                                        <BadgeCheck className="size-3.5" />
                                        Certificate,{' '}
                                        {validityLabel(
                                            program.certificate_validity_months,
                                        )}
                                    </span>
                                )}
                                {program.budget !== null && (
                                    <span>
                                        Budget {formatMoney(program.budget)}
                                    </span>
                                )}
                            </span>
                            {program.description && (
                                <span className="mt-1 line-clamp-2 block max-w-3xl">
                                    {program.description}
                                </span>
                            )}
                        </>
                    }
                    actions={
                        can.manage && (
                            <>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setEditProgram(true)}
                                >
                                    <Pencil className="size-4" />
                                    Edit
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-muted-foreground hover:text-destructive"
                                    onClick={() => setArchiveOpen(true)}
                                >
                                    <Archive className="size-4" />
                                    Archive
                                </Button>
                            </>
                        )
                    }
                />

                <StatTiles
                    tiles={[
                        {
                            key: 'seats',
                            label: 'Seats taken',
                            value: program.active_count.toLocaleString(),
                            hint:
                                program.capacity === null
                                    ? 'uncapped'
                                    : `of ${program.capacity}`,
                            icon: Users,
                            accent: 'teal',
                        },
                        {
                            key: 'completion',
                            label: 'Completion rate',
                            value: rate === null ? '—' : `${rate}%`,
                            hint: `${analytics.completed} of ${analytics.total}`,
                            icon: CircleCheckBig,
                            accent: 'emerald',
                        },
                        {
                            key: 'score',
                            label: 'Average score',
                            value:
                                analytics.average_score === null
                                    ? '—'
                                    : `${analytics.average_score}%`,
                            icon: TrendingUp,
                            accent: 'sky',
                            valueClassName:
                                analytics.average_score === null
                                    ? undefined
                                    : scoreTone(analytics.average_score),
                        },
                        {
                            key: 'attendance',
                            label: 'Attendance',
                            value:
                                attendance.rate === null
                                    ? '—'
                                    : `${attendance.rate}%`,
                            hint: `${attendance.held} of ${attendance.sessions} held`,
                            icon: CalendarCheck2,
                            accent: 'violet',
                        },
                        {
                            key: 'at-risk',
                            label: 'At risk',
                            value: analytics.at_risk.toLocaleString(),
                            hint: 'past the end',
                            icon: TriangleAlert,
                            accent: 'amber',
                            valueClassName:
                                analytics.at_risk > 0
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : undefined,
                        },
                        {
                            key: 'dropped',
                            label: 'Dropped',
                            value: analytics.dropped.toLocaleString(),
                            icon: UserX,
                            accent: 'rose',
                            valueClassName:
                                analytics.dropped > 0
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : undefined,
                        },
                    ]}
                />

                <div
                    className="flex items-center gap-1 overflow-x-auto border-b border-border"
                    role="tablist"
                    aria-label="Program views"
                >
                    {TABS.map((value) => (
                        <button
                            key={value}
                            type="button"
                            role="tab"
                            aria-selected={tab === value}
                            onClick={() => setTab(value)}
                            className={cn(
                                'border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                tab === value
                                    ? 'border-[#0ABFBF] text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {value === 'roster'
                                ? 'Roster'
                                : value === 'sessions'
                                  ? 'Sessions'
                                  : 'Costs'}
                            <span className="ml-1.5 text-muted-foreground tabular-nums">
                                {value === 'roster'
                                    ? enrollments.length
                                    : value === 'sessions'
                                      ? sessions.length
                                      : costs.length}
                            </span>
                        </button>
                    ))}
                </div>

                {tab === 'roster' && (
                    <>
                        {can.manage && (
                            <RequestsCard
                                requests={requests}
                                seatsRemaining={program.seats_remaining}
                            />
                        )}

                        <RosterTable
                            programHashid={program.hashid}
                            enrollments={enrollments}
                            attendance={attendance.by_enrollment}
                            canManage={can.manage}
                            onEnroll={() => setEnrollOpen(true)}
                            onEdit={setEdit}
                            onRemove={setRemove}
                        />

                        {ai_available && enrollments.length > 0 && (
                            <TrainingInsightsPanel
                                key={program.hashid}
                                hashid={program.hashid}
                                saved={program.ai_insights}
                            />
                        )}
                    </>
                )}

                {tab === 'sessions' && (
                    <SessionsPanel
                        programHashid={program.hashid}
                        window={{
                            start: program.start_date,
                            end: program.end_date,
                        }}
                        sessions={sessions}
                        enrollments={enrollments}
                        attendance={attendance}
                        canManage={can.manage}
                    />
                )}

                {tab === 'costs' && (
                    <CostsPanel
                        programHashid={program.hashid}
                        money={money}
                        costs={costs}
                        canManage={can.manage}
                    />
                )}
            </PageBody>

            <BulkEnrollDialog
                open={enrollOpen}
                onOpenChange={setEnrollOpen}
                programHashid={program.hashid}
                enrollable={enrollable}
                seatsRemaining={program.seats_remaining}
            />

            <EditEnrollmentDialog
                open={edit !== null}
                onOpenChange={(open) => !open && setEdit(null)}
                enrollment={edit}
            />

            <ProgramFormModal
                program={editProgram ? program : null}
                open={editProgram}
                onOpenChange={setEditProgram}
            />

            <ConfirmDialog
                open={remove !== null}
                onOpenChange={(open) => !open && setRemove(null)}
                title="Remove enrollment?"
                description={`${remove?.employee?.full_name ?? 'This employee'} will be removed from ${program.name}.`}
                confirmLabel="Remove"
                destructive
                processing={processing}
                onConfirm={confirmRemove}
            />

            <ConfirmDialog
                open={archiveOpen}
                onOpenChange={setArchiveOpen}
                title={`Archive "${program.name}"?`}
                description="It is hidden from the active list; enrollments are kept. You can restore it later."
                confirmLabel="Archive"
                destructive
                processing={processing}
                onConfirm={archive}
            />
        </>
    );
}

TrainingShow.layout = (props: TrainingShowPageProps) => ({
    breadcrumbs: [
        { title: 'Training & Development', href: '/training' },
        {
            title: props.program.name,
            href: trainingRoutes.show(props.program.hashid),
        },
    ],
});
