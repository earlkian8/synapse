import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Pencil, Trash2, UserPlus, Users } from 'lucide-react';
import { useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { removeEnrollment } from '@/features/benefits/api';
import { EnrollmentStatusBadge } from '@/features/benefits/components/benefit-status-badge';
import { EnrollDialog } from '@/features/benefits/components/enroll-dialog';
import {
    CATEGORY_LABELS,
    CATEGORY_META,
    FREQUENCY_LABELS,
    formatDate,
    formatPeso,
} from '@/features/benefits/constants';
import { benefitsRoutes } from '@/features/benefits/routes';
import type {
    BenefitEnrollment,
    BenefitsShowPageProps,
} from '@/features/benefits/types';
import { ConfirmDialog } from '@/features/payroll/components/confirm-dialog';
import { cn } from '@/lib/utils';

export default function BenefitsShow() {
    const { plan, enrollable, can } = usePage<BenefitsShowPageProps>().props;
    const meta = CATEGORY_META[plan.category];
    const Icon = meta.icon;
    const enrollments = plan.enrollments ?? [];

    const [enrollOpen, setEnrollOpen] = useState(false);
    const [edit, setEdit] = useState<BenefitEnrollment | null>(null);
    const [remove, setRemove] = useState<BenefitEnrollment | null>(null);
    const [processing, setProcessing] = useState(false);

    const openEnroll = () => {
        setEdit(null);
        setEnrollOpen(true);
    };

    const openEdit = (enrollment: BenefitEnrollment) => {
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

    return (
        <>
            <Head title={`Benefits — ${plan.name}`} />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <Link
                    href={benefitsRoutes.index}
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    All benefits
                </Link>

                {/* Plan header */}
                <div className="flex flex-col gap-5 rounded-xl border border-sidebar-border/70 bg-card p-5 shadow-sm dark:border-sidebar-border">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex min-w-0 items-start gap-3">
                            <span
                                className={cn(
                                    'flex size-12 shrink-0 items-center justify-center rounded-xl',
                                    meta.accent,
                                )}
                            >
                                <Icon className="size-6" />
                            </span>
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1 className="text-xl font-semibold tracking-tight">
                                        {plan.name}
                                    </h1>
                                    <span className="rounded-full border border-border bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                        {CATEGORY_LABELS[plan.category]}
                                    </span>
                                    {!plan.is_active && (
                                        <span className="rounded-full border border-border bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                            Inactive
                                        </span>
                                    )}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {plan.provider ?? 'In-house'}
                                </p>
                                {plan.description && (
                                    <p className="mt-2 max-w-prose text-sm text-muted-foreground">
                                        {plan.description}
                                    </p>
                                )}
                            </div>
                        </div>

                        {can.manage && (
                            <Button size="sm" onClick={openEnroll}>
                                <UserPlus className="size-4" />
                                Enroll employee
                            </Button>
                        )}
                    </div>

                    {/* Cost + headcount strip */}
                    <div className="grid grid-cols-2 gap-3 border-t border-sidebar-border/60 pt-4 sm:grid-cols-4 dark:border-sidebar-border">
                        <Stat
                            label={`Employer ${FREQUENCY_LABELS[plan.frequency].toLowerCase()}`}
                            value={formatPeso(plan.employer_cost)}
                        />
                        <Stat
                            label={`Employee ${FREQUENCY_LABELS[plan.frequency].toLowerCase()}`}
                            value={formatPeso(plan.employee_cost)}
                        />
                        <Stat
                            label="Total per period"
                            value={formatPeso(plan.total_cost)}
                            highlight
                        />
                        <Stat
                            label="Active enrollees"
                            value={plan.active_count.toLocaleString()}
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
                                ? 'Use "Enroll employee" to add people to this plan.'
                                : 'No employees are enrolled in this plan.'}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                        <div className="flex items-center justify-between border-b border-border px-4 py-3">
                            <p className="text-sm font-semibold">
                                Enrolled employees
                            </p>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {enrollments.length} total
                            </span>
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
                                            {enrollment.reference_no
                                                ? ` · ${enrollment.reference_no}`
                                                : ''}
                                        </p>
                                    </div>
                                    <span className="hidden text-xs text-muted-foreground tabular-nums sm:block">
                                        {formatDate(enrollment.enrolled_on)}
                                    </span>
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
                planHashid={plan.hashid}
                enrollable={enrollable}
                enrollment={edit}
            />

            <ConfirmDialog
                open={remove !== null}
                onOpenChange={(open) => !open && setRemove(null)}
                title="Remove enrollment?"
                description={`${remove?.employee?.full_name ?? 'This employee'} will be removed from ${plan.name}.`}
                confirmLabel="Remove"
                destructive
                processing={processing}
                onConfirm={confirmRemove}
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
    value: string;
    highlight?: boolean;
}) {
    return (
        <div className="flex flex-col">
            <span className="text-[11px] tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span
                className={
                    highlight
                        ? 'text-lg font-semibold tracking-tight text-[#0a8b91] tabular-nums dark:text-[#0ABFBF]'
                        : 'text-lg font-semibold tracking-tight tabular-nums'
                }
            >
                {value}
            </span>
        </div>
    );
}

BenefitsShow.layout = {
    breadcrumbs: [{ title: 'Benefits', href: '/benefits' }],
};
