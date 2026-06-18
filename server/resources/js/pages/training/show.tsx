import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArrowLeft,
    GraduationCap,
    Pencil,
    Trash2,
    UserPlus,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/features/payroll/components/confirm-dialog';
import { removeEnrollment } from '@/features/training/api';
import { EnrollDialog } from '@/features/training/components/enroll-dialog';
import { ProgramFormSheet } from '@/features/training/components/program-form-sheet';
import {
    EnrollmentStatusBadge,
    ProgramStatusBadge,
} from '@/features/training/components/training-status-badge';
import {
    formatDate,
    formatDateRange,
    formatScore,
} from '@/features/training/constants';
import { trainingRoutes } from '@/features/training/routes';
import type {
    TrainingEnrollment,
    TrainingShowPageProps,
} from '@/features/training/types';
import { cn } from '@/lib/utils';

export default function TrainingShow() {
    const { program, enrollable, can } = usePage<TrainingShowPageProps>().props;
    const enrollments = program.enrollments ?? [];

    const [enrollOpen, setEnrollOpen] = useState(false);
    const [edit, setEdit] = useState<TrainingEnrollment | null>(null);
    const [editProgram, setEditProgram] = useState(false);
    const [remove, setRemove] = useState<TrainingEnrollment | null>(null);
    const [archiveOpen, setArchiveOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const openEnroll = () => {
        setEdit(null);
        setEnrollOpen(true);
    };

    const openEdit = (enrollment: TrainingEnrollment) => {
        setEdit(enrollment);
        setEnrollOpen(true);
    };

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

    return (
        <>
            <Head title={`Training — ${program.name}`} />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <Link
                    href={trainingRoutes.index}
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    All programs
                </Link>

                {/* Program header */}
                <div className="flex flex-col gap-5 rounded-xl border border-sidebar-border/70 bg-card p-5 shadow-sm dark:border-sidebar-border">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex min-w-0 items-start gap-3">
                            <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                <GraduationCap className="size-6" />
                            </span>
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1 className="text-xl font-semibold tracking-tight">
                                        {program.name}
                                    </h1>
                                    <ProgramStatusBadge
                                        status={program.status}
                                    />
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {program.provider ?? 'In-house'} ·{' '}
                                    {formatDateRange(
                                        program.start_date,
                                        program.end_date,
                                    )}
                                </p>
                                {program.description && (
                                    <p className="mt-2 max-w-prose text-sm text-muted-foreground">
                                        {program.description}
                                    </p>
                                )}
                            </div>
                        </div>

                        {can.manage && (
                            <div className="flex items-center gap-2">
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
                            </div>
                        )}
                    </div>

                    {/* Stat strip */}
                    <div className="grid grid-cols-2 gap-3 border-t border-sidebar-border/60 pt-4 sm:grid-cols-4 dark:border-sidebar-border">
                        <Stat
                            label="Seats"
                            value={
                                program.capacity === null
                                    ? `${program.active_count} · uncapped`
                                    : `${program.active_count} / ${program.capacity}`
                            }
                        />
                        <Stat
                            label="Completed"
                            value={program.completed_count.toLocaleString()}
                        />
                        <Stat
                            label="Total enrolled"
                            value={program.enrollments_count.toLocaleString()}
                        />
                        <Stat
                            label="Schedule"
                            value={formatDateRange(
                                program.start_date,
                                program.end_date,
                            )}
                            highlight
                        />
                    </div>
                </div>

                {/* Roster */}
                {enrollments.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-14 text-center dark:border-sidebar-border">
                        <span className="flex size-10 items-center justify-center rounded-full bg-muted">
                            <Users className="size-5 text-muted-foreground" />
                        </span>
                        <p className="text-sm font-medium">
                            No one enrolled yet
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {can.manage
                                ? 'Use "Enroll employee" to add people to this program.'
                                : 'No employees are enrolled in this program.'}
                        </p>
                        {can.manage && (
                            <Button
                                size="sm"
                                className="mt-1"
                                onClick={openEnroll}
                            >
                                <UserPlus className="size-4" />
                                Enroll employee
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                        <div className="flex items-center justify-between border-b border-border px-4 py-3">
                            <p className="text-sm font-semibold">
                                Enrolled employees
                            </p>
                            {can.manage && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={openEnroll}
                                >
                                    <UserPlus className="size-4" />
                                    Enroll
                                </Button>
                            )}
                        </div>
                        <ul className="divide-y divide-border">
                            {enrollments.map((enrollment) => (
                                <li
                                    key={enrollment.id}
                                    className="flex items-center gap-3 px-4 py-3"
                                >
                                    <PersonAvatar
                                        name={
                                            enrollment.employee?.full_name ??
                                            'Unknown'
                                        }
                                        initials={
                                            enrollment.employee?.initials ?? '?'
                                        }
                                        photo={enrollment.employee?.photo}
                                        className="size-9"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {enrollment.employee?.full_name ??
                                                'Unknown employee'}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {enrollment.employee?.position ??
                                                enrollment.employee
                                                    ?.employee_no ??
                                                '—'}
                                            {enrollment.completed_at
                                                ? ` · ${formatDate(enrollment.completed_at)}`
                                                : ''}
                                        </p>
                                    </div>
                                    {enrollment.score !== null && (
                                        <span className="hidden text-sm font-semibold text-foreground tabular-nums sm:block">
                                            {formatScore(enrollment.score)}
                                        </span>
                                    )}
                                    <EnrollmentStatusBadge
                                        status={enrollment.status}
                                    />
                                    {can.manage && (
                                        <div className="flex items-center gap-1">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                onClick={() =>
                                                    openEdit(enrollment)
                                                }
                                                aria-label="Edit enrollment"
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8 text-muted-foreground hover:text-destructive"
                                                onClick={() =>
                                                    setRemove(enrollment)
                                                }
                                                aria-label="Remove enrollment"
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            <EnrollDialog
                open={enrollOpen}
                onOpenChange={setEnrollOpen}
                programHashid={program.hashid}
                enrollable={enrollable}
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

function Stat({
    label,
    value,
    highlight = false,
}: {
    label: string;
    value: ReactNode;
    highlight?: boolean;
}) {
    return (
        <div className="flex flex-col">
            <span className="text-[11px] tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span
                className={cn(
                    'text-sm font-semibold tracking-tight tabular-nums',
                    highlight && 'text-[#0a8b91] dark:text-[#0ABFBF]',
                )}
            >
                {value}
            </span>
        </div>
    );
}

TrainingShow.layout = {
    breadcrumbs: [{ title: 'Training', href: '/training' }],
};
