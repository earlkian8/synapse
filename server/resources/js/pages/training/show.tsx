import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
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
import { EditEnrollmentDialog } from '@/features/training/components/edit-enrollment-dialog';
import { ProgramFormSheet } from '@/features/training/components/program-form-sheet';
import { RosterTable } from '@/features/training/components/roster-table';
import { TrainingInsightsPanel } from '@/features/training/components/training-insights';
import { ProgramStatusBadge } from '@/features/training/components/training-status-badge';
import { formatDateRange, scoreTone } from '@/features/training/constants';
import { trainingRoutes } from '@/features/training/routes';
import type {
    TrainingEnrollment,
    TrainingShowPageProps,
} from '@/features/training/types';

export default function TrainingShow() {
    const { program, enrollable, analytics, ai_available, can } =
        usePage<TrainingShowPageProps>().props;
    const enrollments = program.enrollments ?? [];

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
                            key: 'at-risk',
                            label: 'At risk',
                            value: analytics.at_risk.toLocaleString(),
                            hint: 'unfinished past end',
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

                <RosterTable
                    programHashid={program.hashid}
                    enrollments={enrollments}
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

            <ProgramFormSheet
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
        { title: 'Training', href: '/training' },
        {
            title: props.program.name,
            href: trainingRoutes.show(props.program.hashid),
        },
    ],
});
