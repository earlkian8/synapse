import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Pencil,
    Plus,
    Scale,
    Star,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { PolicyFormModal } from '@/features/attendance-policy-config/components/policy-form-modal';
import { policyHeadline } from '@/features/attendance-policy-config/constants';
import { attendancePolicyRoutes } from '@/features/attendance-policy-config/routes';
import type {
    AttendancePoliciesPageProps,
    AttendancePolicy,
} from '@/features/attendance-policy-config/types';
import { cn } from '@/lib/utils';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

/**
 * Company Setup → Attendance Policies (ADR 0038): how each day is judged —
 * grace, rounding, lateness thresholds, overtime, breaks and night work — chosen
 * from a preset and adjusted, never written as a formula.
 */
export default function SetupAttendancePolicies() {
    const { policies, archivedPolicies, presets, fallback, sources, can } =
        usePage<AttendancePoliciesPageProps>().props;

    const [form, setForm] = useState<{
        open: boolean;
        policy: AttendancePolicy | null;
    }>({ open: false, policy: null });
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

    const hasDefault = policies.some((policy) => policy.is_default);

    return (
        <>
            <Head title="Attendance Policies" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex max-w-2xl flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Attendance Policies
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            How a day is judged — when someone is late or short,
                            how times are rounded, what counts as overtime and
                            night work. A day keeps the policy it was judged by.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {archivedPolicies.length > 0 && (
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
                                    ({archivedPolicies.length})
                                </span>
                            </Button>
                        )}
                        {can.manage && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    setForm({ open: true, policy: null })
                                }
                            >
                                <Plus className="size-4" />
                                New policy
                            </Button>
                        )}
                    </div>
                </div>

                <p className="text-xs text-muted-foreground">
                    Which policy applies, most specific first: the one on
                    someone’s schedule assignment, then their schedule’s, their
                    department’s, the company default, and otherwise the
                    built-in rules.
                    {!hasDefault &&
                        policies.length > 0 &&
                        ' No policy is the company default yet, so anyone not covered by one is judged by the built-in rules.'}
                </p>

                {policies.length === 0 ? (
                    <div className="flex flex-col items-start gap-3 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-5 py-8 dark:border-sidebar-border">
                        <p className="max-w-xl text-sm text-muted-foreground">
                            No policies yet. Everyone is judged by the built-in
                            rules: exact punch times, each schedule’s own grace,
                            overtime after the day’s required hours, and breaks
                            exactly as punched.
                        </p>
                        {can.manage && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setForm({ open: true, policy: null })
                                }
                            >
                                <Plus className="size-4" />
                                Start from a preset
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="divide-y divide-border overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                        {policies.map((policy) => (
                            <PolicyRow
                                key={policy.id}
                                policy={policy}
                                canManage={can.manage}
                                onEdit={() => setForm({ open: true, policy })}
                                onToggleDefault={() =>
                                    router.patch(
                                        attendancePolicyRoutes.setDefault(
                                            policy.hashid,
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                onArchive={() =>
                                    askConfirm({
                                        title: `Archive "${policy.name}"?`,
                                        description:
                                            'It stops being offered, and stops being the company default. Schedules, departments and assignments that name it keep using it until you change them.',
                                        confirmLabel: 'Archive',
                                        run: () =>
                                            router.delete(
                                                attendancePolicyRoutes.destroy(
                                                    policy.hashid,
                                                ),
                                                withProcessing,
                                            ),
                                    })
                                }
                            />
                        ))}
                    </div>
                )}

                {showArchived && archivedPolicies.length > 0 && (
                    <div className="space-y-2">
                        {archivedPolicies.map((policy) => (
                            <div
                                key={policy.id}
                                className="flex items-center gap-3 rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-3 py-2.5 dark:border-sidebar-border"
                            >
                                <span className="min-w-0 flex-1 truncate text-sm font-medium text-muted-foreground">
                                    {policy.name}
                                </span>
                                {can.manage && (
                                    <>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.patch(
                                                    attendancePolicyRoutes.restore(
                                                        policy.hashid,
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
                                            aria-label="Delete permanently"
                                            onClick={() =>
                                                askConfirm({
                                                    title: `Permanently delete "${policy.name}"?`,
                                                    description:
                                                        'This cannot be undone. A policy a schedule, department or assignment still names cannot be deleted. Days already judged by it keep what it said.',
                                                    confirmLabel:
                                                        'Delete permanently',
                                                    run: () =>
                                                        router.delete(
                                                            attendancePolicyRoutes.forceDelete(
                                                                policy.hashid,
                                                            ),
                                                            withProcessing,
                                                        ),
                                                })
                                            }
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

            <PolicyFormModal
                policy={form.policy}
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
                presets={presets}
                fallback={fallback}
                sources={sources}
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

/** One policy: what it leads with, where it is used, and what can be done to it. */
function PolicyRow({
    policy,
    canManage,
    onEdit,
    onToggleDefault,
    onArchive,
}: {
    policy: AttendancePolicy;
    canManage: boolean;
    onEdit: () => void;
    onToggleDefault: () => void;
    onArchive: () => void;
}) {
    const usage = [
        policy.schedules_count > 0 &&
            `${policy.schedules_count} ${policy.schedules_count === 1 ? 'schedule' : 'schedules'}`,
        policy.departments_count > 0 &&
            `${policy.departments_count} ${policy.departments_count === 1 ? 'department' : 'departments'}`,
        policy.assignments_count > 0 &&
            `${policy.assignments_count} ${policy.assignments_count === 1 ? 'assignment' : 'assignments'}`,
    ].filter(Boolean);

    return (
        <div className="flex items-start gap-3 px-4 py-3">
            <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Scale className="size-4" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="truncate text-sm font-medium">
                        {policy.name}
                    </p>
                    {policy.is_default && (
                        <span className="inline-flex items-center gap-1 rounded-full border border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-1.5 py-0.5 text-[10px] font-medium text-[#0a8b91] dark:text-[#0ABFBF]">
                            <Star className="size-3" />
                            Company default
                        </span>
                    )}
                    {policy.preset_name && (
                        <span className="rounded-full border border-border px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                            From {policy.preset_name}
                        </span>
                    )}
                </div>
                <ul className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                    {policyHeadline(policy.settings).map((line) => (
                        <li key={line}>{line}</li>
                    ))}
                </ul>
                {(usage.length > 0 || policy.description) && (
                    <p className="mt-1 text-xs text-muted-foreground/80">
                        {policy.description}
                        {policy.description && usage.length > 0 && '. '}
                        {usage.length > 0 && `Named on ${usage.join(', ')}.`}
                    </p>
                )}
            </div>
            {canManage && (
                <div className="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        className={cn(
                            'size-8',
                            policy.is_default
                                ? 'text-[#0a8b91] dark:text-[#0ABFBF]'
                                : 'text-muted-foreground',
                        )}
                        onClick={onToggleDefault}
                        aria-pressed={policy.is_default}
                        aria-label={
                            policy.is_default
                                ? 'Clear the company default'
                                : 'Make this the company default'
                        }
                        title={
                            policy.is_default
                                ? 'Clear the company default'
                                : 'Make this the company default'
                        }
                    >
                        <Star
                            className={cn(
                                'size-4',
                                policy.is_default && 'fill-current',
                            )}
                        />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        onClick={onEdit}
                        aria-label="Edit"
                    >
                        <Pencil className="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 text-muted-foreground hover:text-destructive"
                        onClick={onArchive}
                        aria-label="Archive"
                    >
                        <Archive className="size-4" />
                    </Button>
                </div>
            )}
        </div>
    );
}

SetupAttendancePolicies.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Attendance Policies', href: '/setup/attendance-policies' },
    ],
};
