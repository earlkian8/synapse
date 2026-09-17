import { useForm } from '@inertiajs/react';
import { CalendarCog, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { FormSelect } from '@/components/form-select';
import InputError from '@/components/input-error';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { SHIFT_SOURCE_LABELS } from '../constants';
import { attendanceRoutes } from '../routes';
import type { GridEmployee, RosterCell, ScheduleRef } from '../types';

export type RosterTarget = { employee: GridEmployee; cell: RosterCell };

/** What the day can be changed to. */
type Mode = 'schedule' | 'hours' | 'rest';

/**
 * Change one person's shift for one date — a swap, a Saturday call-in, or a day
 * off (ADR 0037). The override wins over whatever schedule they are on, and
 * clearing it hands the day straight back.
 */
export function RosterEntryDialog({
    target,
    schedules,
    open,
    onOpenChange,
}: {
    target: RosterTarget | null;
    schedules: ScheduleRef[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                {target && (
                    <>
                        <ModalHeader
                            icon={
                                <PersonAvatar
                                    name={target.employee.full_name}
                                    initials={target.employee.initials}
                                    photo={target.employee.photo}
                                    className="size-10"
                                />
                            }
                            title={target.employee.full_name}
                            description={`${formatDate(target.cell.date)} — currently ${target.cell.label.toLowerCase()}`}
                            meta={
                                <span className="inline-flex items-center gap-1 rounded-full border border-border px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                                    {SHIFT_SOURCE_LABELS[target.cell.source]}
                                    {target.cell.schedule_name
                                        ? ` · ${target.cell.schedule_name}`
                                        : ''}
                                </span>
                            }
                        />
                        <FormBody
                            key={`${target.employee.id}-${target.cell.date}`}
                            target={target}
                            schedules={schedules}
                            onDone={() => onOpenChange(false)}
                        />
                    </>
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    target,
    schedules,
    onDone,
}: {
    target: RosterTarget;
    schedules: ScheduleRef[];
    onDone: () => void;
}) {
    const { cell } = target;

    const [mode, setMode] = useState<Mode>(() => {
        if (cell.source === 'roster' && !cell.is_working_day) {
            return 'rest';
        }

        return cell.source === 'roster' && cell.segments.length > 0
            ? 'hours'
            : 'schedule';
    });

    const {
        data,
        setData,
        post,
        delete: destroy,
        transform,
        processing,
        errors,
    } = useForm({
        employee_id: target.employee.id,
        date: cell.date,
        work_schedule_id: '',
        start: cell.segments[0]?.start ?? '08:00',
        end: cell.segments[0]?.end ?? '17:00',
        reason: cell.reason ?? '',
        // Declared so the server's errors on the transformed payload have
        // somewhere to land; `transform` below decides what is actually sent.
        segments: null as { start: string; end: string }[] | null,
        is_rest_day: false,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        // Only the chosen shape is sent, so switching between them never leaves
        // a stale schedule or a stale pair of times behind.
        transform((payload) => ({
            employee_id: payload.employee_id,
            date: payload.date,
            reason: payload.reason || null,
            is_rest_day: mode === 'rest',
            work_schedule_id:
                mode === 'schedule' ? payload.work_schedule_id : null,
            segments:
                mode === 'hours'
                    ? [{ start: payload.start, end: payload.end }]
                    : null,
        }));

        post(attendanceRoutes.rosterEntry, {
            preserveScroll: true,
            onSuccess: () => onDone(),
        });
    };

    const clear = () => {
        if (!cell.entry_hashid) {
            return;
        }

        destroy(attendanceRoutes.rosterEntryDestroy(cell.entry_hashid), {
            preserveScroll: true,
            onSuccess: () => onDone(),
        });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <div className="grid gap-2 sm:grid-cols-3">
                    <ModeButton
                        active={mode === 'schedule'}
                        onClick={() => setMode('schedule')}
                        label="Another shift"
                        hint="Borrow a schedule's hours"
                    />
                    <ModeButton
                        active={mode === 'hours'}
                        onClick={() => setMode('hours')}
                        label="Custom hours"
                        hint="Just for this day"
                    />
                    <ModeButton
                        active={mode === 'rest'}
                        onClick={() => setMode('rest')}
                        label="Day off"
                        hint="Not expected in"
                    />
                </div>

                {mode === 'schedule' && (
                    <div>
                        <Label
                            htmlFor="roster-schedule"
                            className="mb-1.5 block"
                        >
                            Shift
                            <span className="ml-0.5 text-destructive">*</span>
                        </Label>
                        <FormSelect
                            id="roster-schedule"
                            value={data.work_schedule_id}
                            onChange={(value) =>
                                setData('work_schedule_id', value)
                            }
                            options={schedules.map((schedule) => ({
                                value: String(schedule.id),
                                label: schedule.name,
                            }))}
                            placeholder="Choose a shift…"
                        />
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            That schedule's hours for this date only. Their own
                            schedule is untouched.
                        </p>
                        <InputError
                            message={errors.work_schedule_id ?? errors.segments}
                            className="mt-1.5"
                        />
                    </div>
                )}

                {mode === 'hours' && (
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <Label
                                htmlFor="roster-start"
                                className="mb-1.5 block"
                            >
                                Start
                            </Label>
                            <Input
                                id="roster-start"
                                type="time"
                                value={data.start}
                                onChange={(e) =>
                                    setData('start', e.target.value)
                                }
                                required
                            />
                        </div>
                        <div>
                            <Label
                                htmlFor="roster-end"
                                className="mb-1.5 block"
                            >
                                End
                            </Label>
                            <Input
                                id="roster-end"
                                type="time"
                                value={data.end}
                                onChange={(e) => setData('end', e.target.value)}
                                required
                            />
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                An end before the start runs overnight.
                            </p>
                        </div>
                        <InputError
                            message={errors.segments}
                            className="col-span-2"
                        />
                    </div>
                )}

                {mode === 'rest' && (
                    <p className="rounded-lg border border-border bg-muted/40 px-3 py-2.5 text-sm text-muted-foreground">
                        {target.employee.full_name.split(' ')[0]} is not
                        expected in on {formatDate(cell.date)}. The day shows as
                        a rest day rather than an absence.
                    </p>
                )}

                <div>
                    <Label htmlFor="roster-reason" className="mb-1.5 block">
                        Reason
                    </Label>
                    <Input
                        id="roster-reason"
                        value={data.reason}
                        onChange={(e) => setData('reason', e.target.value)}
                        placeholder="e.g. Covering for Ben"
                        maxLength={160}
                    />
                    <InputError message={errors.reason} className="mt-1.5" />
                </div>
            </ModalBody>

            <ModalFooter>
                {cell.entry_hashid && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={clear}
                        disabled={processing}
                        className="mr-auto text-muted-foreground hover:text-destructive"
                    >
                        <Trash2 className="size-4" />
                        Clear override
                    </Button>
                )}
                <Button
                    type="button"
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    Save the day
                </Button>
            </ModalFooter>
        </form>
    );
}

function ModeButton({
    active,
    onClick,
    label,
    hint,
}: {
    active: boolean;
    onClick: () => void;
    label: string;
    hint: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'rounded-lg border px-3 py-2 text-left transition-colors',
                active
                    ? 'border-[#0ABFBF] bg-[#0ABFBF]/5'
                    : 'border-input hover:bg-muted',
            )}
        >
            <span
                className={cn(
                    'block text-sm font-medium',
                    active && 'text-[#0a8b91] dark:text-[#0ABFBF]',
                )}
            >
                {label}
            </span>
            <span className="mt-0.5 block text-xs leading-snug text-muted-foreground">
                {hint}
            </span>
        </button>
    );
}

/** The header icon used when there is no person to show. */
export function RosterIcon() {
    return (
        <ModalIcon>
            <CalendarCog />
        </ModalIcon>
    );
}

function formatDate(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'short',
        day: 'numeric',
    });
}
