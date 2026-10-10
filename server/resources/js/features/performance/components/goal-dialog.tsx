import {
    CalendarClock,
    CheckCircle2,
    CircleSlash,
    Flag,
    Pencil,
    RotateCcw,
    Trash2,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormField } from '@/components/form-field';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { checkInGoal, deleteGoal, setGoalStatus } from '../api';
import {
    formatDate,
    formatTimestamp,
    GOAL_HEALTH,
    sinceLabel,
    TEXTAREA,
} from '../constants';
import type { GoalHealth, PerformanceGoal } from '../types';
import { GoalProgressBar, GoalStateChip } from './goal-progress';

type Props = {
    goal: PerformanceGoal | null;
    onOpenChange: (open: boolean) => void;
    /** `owner`: the person's own goal (My goals); `manager`: HR's Goals screen. */
    as: 'owner' | 'manager';
    /** HR may edit, close and delete. */
    canManage?: boolean;
    onEdit?: (goal: PerformanceGoal) => void;
};

/**
 * One goal, opened (ADR 0073): where it stands, a check-in — the value now, how
 * it is going and a note — and every check-in before it, newest first. HR can
 * also edit it, close it as achieved, missed or dropped, or delete one set by
 * mistake; its owner can delete a goal they added while it has no check-ins.
 */
export function GoalDialog({
    goal,
    onOpenChange,
    as,
    canManage = false,
    onEdit,
}: Props) {
    return (
        <Modal open={goal !== null} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                {goal && (
                    <GoalDialogBody
                        key={goal.hashid}
                        goal={goal}
                        as={as}
                        canManage={canManage}
                        onEdit={onEdit}
                        onClose={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function GoalDialogBody({
    goal,
    as,
    canManage,
    onEdit,
    onClose,
}: {
    goal: PerformanceGoal;
    as: 'owner' | 'manager';
    canManage: boolean;
    onEdit?: (goal: PerformanceGoal) => void;
    onClose: () => void;
}) {
    const [value, setValue] = useState(String(goal.current_value));
    const [health, setHealth] = useState<GoalHealth>(goal.health ?? 'on_track');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const cycleClosed = goal.period?.status === 'closed';
    const canCheckIn =
        goal.status === 'active' &&
        !cycleClosed &&
        (as === 'owner' || canManage);
    const canDelete =
        goal.check_ins_count === 0 &&
        (as === 'manager' ? canManage : goal.created_by_owner);
    const checkIns = goal.check_ins ?? [];

    const handlers = {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    const submit = () =>
        checkInGoal(
            goal.hashid,
            { value: Number(value), health, note: note.trim() || null },
            as === 'owner',
            {
                ...handlers,
                onSuccess: () => {
                    setNote('');
                    setErrors({});
                },
                onError: setErrors,
            },
        );

    return (
        <>
            <ModalHeader
                icon={
                    <ModalIcon>
                        <Flag />
                    </ModalIcon>
                }
                title={goal.title}
                description={[
                    as === 'manager' ? goal.employee?.full_name : null,
                    goal.period?.name,
                    goal.due_on ? `Due ${formatDate(goal.due_on)}` : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
            />

            <ModalBody className="space-y-5">
                {goal.description && (
                    <p className="text-sm whitespace-pre-line text-muted-foreground">
                        {goal.description}
                    </p>
                )}

                <div className="rounded-lg border border-border bg-muted/20 px-4 py-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-2xl font-semibold tracking-tight tabular-nums">
                            {Math.round(goal.progress)}%
                            <span className="ml-2 text-sm font-normal text-muted-foreground">
                                {goal.measure === 'number'
                                    ? `${goal.current_label} now, from ${goal.start_label} toward ${goal.target_label}`
                                    : 'complete'}
                            </span>
                        </p>
                        <GoalStateChip goal={goal} />
                    </div>
                    <GoalProgressBar
                        goal={goal}
                        showLabel={false}
                        className="mt-2"
                    />
                    <p
                        className={cn(
                            'mt-2 text-xs text-muted-foreground',
                            goal.is_stale &&
                                'text-amber-700 dark:text-amber-400',
                        )}
                    >
                        {goal.last_check_in_at
                            ? `Last check-in ${sinceLabel(goal.last_check_in_at)}`
                            : 'Not checked in on yet'}
                        {goal.is_stale && ' — a month without an update'}
                        {goal.weight !== 1 && ` · weight ${goal.weight}`}
                    </p>
                </div>

                {canCheckIn ? (
                    <section aria-labelledby="check-in-heading">
                        <h3
                            id="check-in-heading"
                            className="text-sm font-semibold"
                        >
                            Check in
                        </h3>
                        <div className="mt-2 grid gap-3 sm:grid-cols-[10rem_1fr]">
                            <FormField
                                label={
                                    goal.measure === 'percent'
                                        ? 'Progress now (%)'
                                        : `Now${goal.unit ? ` (${goal.unit})` : ''}`
                                }
                                error={errors.value}
                            >
                                <Input
                                    type="number"
                                    inputMode="decimal"
                                    min={
                                        goal.measure === 'percent'
                                            ? 0
                                            : undefined
                                    }
                                    max={
                                        goal.measure === 'percent'
                                            ? 100
                                            : undefined
                                    }
                                    value={value}
                                    onChange={(e) => setValue(e.target.value)}
                                />
                            </FormField>
                            <FormField
                                label="How it’s going"
                                group
                                error={errors.health}
                            >
                                <div
                                    className="flex flex-wrap gap-1.5"
                                    role="radiogroup"
                                    aria-label="How it’s going"
                                >
                                    {(
                                        Object.keys(GOAL_HEALTH) as GoalHealth[]
                                    ).map((key) => (
                                        <button
                                            key={key}
                                            type="button"
                                            role="radio"
                                            aria-checked={health === key}
                                            onClick={() => setHealth(key)}
                                            className={cn(
                                                'inline-flex min-h-9 items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                                                health === key
                                                    ? GOAL_HEALTH[key].className
                                                    : 'border-border text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'size-2 rounded-full',
                                                    GOAL_HEALTH[key].dot,
                                                )}
                                            />
                                            {GOAL_HEALTH[key].label}
                                        </button>
                                    ))}
                                </div>
                            </FormField>
                        </div>
                        <FormField
                            label="Note"
                            className="mt-3"
                            error={errors.note}
                        >
                            <textarea
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                rows={2}
                                maxLength={1000}
                                placeholder="What moved, what's in the way"
                                className={TEXTAREA}
                            />
                        </FormField>
                        <div className="mt-3 flex justify-end">
                            <Button
                                size="sm"
                                onClick={submit}
                                disabled={processing || value === ''}
                            >
                                {processing && <Spinner />}
                                Check in
                            </Button>
                        </div>
                    </section>
                ) : (
                    goal.status === 'active' &&
                    cycleClosed && (
                        <p className="rounded-lg border border-dashed border-border px-3 py-2.5 text-sm text-muted-foreground">
                            The cycle is closed, so this goal is final.
                        </p>
                    )
                )}

                <section aria-labelledby="history-heading">
                    <h3 id="history-heading" className="text-sm font-semibold">
                        Check-ins
                        <span className="ml-1 font-normal text-muted-foreground tabular-nums">
                            ({checkIns.length})
                        </span>
                    </h3>
                    {checkIns.length === 0 ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            Nobody has checked in yet.
                        </p>
                    ) : (
                        <ol className="mt-2 space-y-0">
                            {checkIns.map((checkIn, i) => (
                                <li
                                    key={checkIn.id}
                                    className="relative flex gap-3 pb-4 last:pb-0"
                                >
                                    {i < checkIns.length - 1 && (
                                        <span
                                            aria-hidden
                                            className="absolute top-4 left-[5px] h-full w-px bg-border"
                                        />
                                    )}
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'relative mt-1.5 size-2.5 shrink-0 rounded-full ring-4 ring-background',
                                            GOAL_HEALTH[checkIn.health].dot,
                                        )}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm">
                                            <span className="font-semibold tabular-nums">
                                                {checkIn.value_label}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {Math.round(checkIn.progress)}
                                                % ·{' '}
                                                {
                                                    GOAL_HEALTH[checkIn.health]
                                                        .label
                                                }
                                            </span>
                                        </p>
                                        {checkIn.note && (
                                            <p className="mt-0.5 text-sm whitespace-pre-line text-muted-foreground">
                                                {checkIn.note}
                                            </p>
                                        )}
                                        <p className="mt-0.5 text-xs text-muted-foreground/80">
                                            {checkIn.by_owner
                                                ? as === 'owner'
                                                    ? 'You'
                                                    : 'Their own check-in'
                                                : (checkIn.author ?? 'HR')}{' '}
                                            ·{' '}
                                            {formatTimestamp(
                                                checkIn.created_at,
                                            )}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>
            </ModalBody>

            <ModalFooter className="sm:justify-between">
                <div className="flex flex-wrap items-center gap-1">
                    {canDelete && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground hover:text-destructive"
                            onClick={() => setConfirmDelete(true)}
                            disabled={processing}
                        >
                            <Trash2 className="size-4" />
                            Delete
                        </Button>
                    )}
                    {as === 'manager' && canManage && !cycleClosed && (
                        <>
                            {goal.status === 'active' && onEdit && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => onEdit(goal)}
                                >
                                    <Pencil className="size-4" />
                                    Edit
                                </Button>
                            )}
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        <CalendarClock className="size-4" />
                                        {goal.status === 'active'
                                            ? 'Close goal'
                                            : 'Change status'}
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="start">
                                    {goal.status !== 'achieved' && (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                setGoalStatus(
                                                    goal.hashid,
                                                    'achieved',
                                                    handlers,
                                                )
                                            }
                                        >
                                            <CheckCircle2 className="size-4" />
                                            Mark achieved
                                        </DropdownMenuItem>
                                    )}
                                    {goal.status !== 'missed' && (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                setGoalStatus(
                                                    goal.hashid,
                                                    'missed',
                                                    handlers,
                                                )
                                            }
                                        >
                                            <XCircle className="size-4" />
                                            Mark missed
                                        </DropdownMenuItem>
                                    )}
                                    {goal.status !== 'dropped' && (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                setGoalStatus(
                                                    goal.hashid,
                                                    'dropped',
                                                    handlers,
                                                )
                                            }
                                        >
                                            <CircleSlash className="size-4" />
                                            Drop — it no longer counts
                                        </DropdownMenuItem>
                                    )}
                                    {goal.status !== 'active' && (
                                        <>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setGoalStatus(
                                                        goal.hashid,
                                                        'active',
                                                        handlers,
                                                    )
                                                }
                                            >
                                                <RotateCcw className="size-4" />
                                                Reopen
                                            </DropdownMenuItem>
                                        </>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </>
                    )}
                </div>
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            </ModalFooter>

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title="Delete this goal?"
                description={`“${goal.title}” will be removed. A goal with check-ins can only be dropped, so its history is kept.`}
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={() =>
                    deleteGoal(goal.hashid, as === 'owner', {
                        ...handlers,
                        onSuccess: () => {
                            setConfirmDelete(false);
                            onClose();
                        },
                    })
                }
            />
        </>
    );
}
