import { useForm } from '@inertiajs/react';
import { CalendarRange, Clock, Plus, X } from 'lucide-react';
import { useMemo } from 'react';
import { FormSelect } from '@/components/form-select';
import InputError from '@/components/input-error';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
    ModalSection,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import {
    blankDay,
    dayLabel,
    defaultPattern,
    DEFAULT_SEGMENT,
    formatMinutes,
    MAX_CYCLE_LENGTH,
    previewDays,
    SCHEDULE_TYPE_OPTIONS,
    segmentMinutes,
    WEEK_LENGTH,
} from '../constants';
import { scheduleConfigRoutes } from '../routes';
import type { ScheduleDay, ScheduleType, WorkSchedule } from '../types';

/** The select's value for "no policy of its own". */
const NO_POLICY = 'none';

type Props = {
    schedule: WorkSchedule | null;
    /** Attendance policies the schedule can be judged by (ADR 0038). */
    policies: { id: number; name: string; is_default: boolean }[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * The shift-template editor (ADR 0037). A schedule is a type, a cycle, and one
 * row per day of that cycle — so "Mon–Fri 8–5, Sat 8–12", a split shift and a
 * four-on-four-off rotation are all the same form.
 *
 * The preview beside it renders the next fortnight from the pattern on screen,
 * using the same day-index rule the server resolves by, so the shape is checked
 * before it is saved rather than discovered on the roster.
 */
export function WorkScheduleFormModal({
    schedule,
    policies,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(schedule);

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="2xl">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Clock />
                        </ModalIcon>
                    }
                    title={isEditing ? schedule!.name : 'New work schedule'}
                    description="A shift pattern employees are assigned to — how each day of its cycle is worked, and how strictly it is judged."
                />

                {open && (
                    <FormBody
                        key={schedule?.id ?? 'new'}
                        schedule={schedule}
                        policies={policies}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    schedule,
    policies,
    onDone,
}: {
    schedule: WorkSchedule | null;
    policies: { id: number; name: string; is_default: boolean }[];
    onDone: () => void;
}) {
    const isEditing = Boolean(schedule);

    const { data, setData, post, transform, processing, errors } = useForm({
        name: schedule?.name ?? '',
        type: (schedule?.type ?? 'fixed') as ScheduleType,
        cycle_length_days: schedule?.cycle_length_days ?? WEEK_LENGTH,
        cycle_anchor_date: schedule?.cycle_anchor_date ?? '',
        grace_minutes: schedule?.grace_minutes ?? 0,
        attendance_policy_id: schedule?.attendance_policy_id
            ? String(schedule.attendance_policy_id)
            : NO_POLICY,
        days:
            schedule && schedule.days.length > 0
                ? schedule.days
                : defaultPattern(),
    });

    const isRotation = data.cycle_length_days !== WEEK_LENGTH;

    const preview = useMemo(
        () =>
            previewDays(
                data.days,
                data.type,
                data.cycle_length_days,
                data.cycle_anchor_date || null,
            ),
        [data.days, data.type, data.cycle_length_days, data.cycle_anchor_date],
    );

    const patchDay = (index: number, patch: Partial<ScheduleDay>) =>
        setData(
            'days',
            data.days.map((day, i) =>
                i === index ? { ...day, ...patch } : day,
            ),
        );

    /** Grow or shrink the cycle, keeping the days that survive. */
    const setCycleLength = (length: number) => {
        const next = Math.min(Math.max(length || 1, 1), MAX_CYCLE_LENGTH);

        setData((current) => ({
            ...current,
            cycle_length_days: next,
            days: Array.from(
                { length: next },
                (_, index) =>
                    current.days[index] ??
                    blankDay(index + 1, index >= WEEK_LENGTH - 2),
            ).map((day, index) => ({ ...day, day_index: index + 1 })),
        }));
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onDone() };

        transform((payload) => ({
            ...payload,
            attendance_policy_id:
                payload.attendance_policy_id === NO_POLICY
                    ? null
                    : Number(payload.attendance_policy_id),
        }));

        post(
            isEditing && schedule
                ? scheduleConfigRoutes.workSchedules.update(schedule.hashid)
                : scheduleConfigRoutes.workSchedules.store,
            options,
        );
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-6">
                {/* Identity and how the day is judged */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <Label htmlFor="schedule-name" className="mb-1.5 block">
                            Name
                            <span className="ml-0.5 text-destructive">*</span>
                        </Label>
                        <Input
                            id="schedule-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Day Shift"
                            required
                        />
                        <InputError message={errors.name} className="mt-1.5" />
                    </div>

                    <div className="sm:col-span-2">
                        <Label className="mb-1.5 block">
                            How the day is judged
                        </Label>
                        <div className="grid gap-2 sm:grid-cols-3">
                            {SCHEDULE_TYPE_OPTIONS.map((option) => {
                                const active = data.type === option.value;

                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() =>
                                            setData('type', option.value)
                                        }
                                        aria-pressed={active}
                                        className={cn(
                                            'rounded-lg border px-3 py-2.5 text-left transition-colors',
                                            active
                                                ? 'border-[#0ABFBF] bg-[#0ABFBF]/5'
                                                : 'border-input hover:bg-muted',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'block text-sm font-medium',
                                                active &&
                                                    'text-[#0a8b91] dark:text-[#0ABFBF]',
                                            )}
                                        >
                                            {option.label}
                                        </span>
                                        <span className="mt-0.5 block text-xs leading-snug text-muted-foreground">
                                            {option.hint}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        <InputError message={errors.type} className="mt-1.5" />
                    </div>

                    <div>
                        <Label
                            htmlFor="schedule-grace"
                            className="mb-1.5 block"
                        >
                            Lateness grace (minutes)
                        </Label>
                        <Input
                            id="schedule-grace"
                            type="number"
                            min="0"
                            max="240"
                            inputMode="numeric"
                            value={data.grace_minutes}
                            onChange={(e) =>
                                setData('grace_minutes', Number(e.target.value))
                            }
                        />
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            A policy that sets its own grace overrides this.
                        </p>
                        <InputError
                            message={errors.grace_minutes}
                            className="mt-1.5"
                        />
                    </div>

                    <div>
                        <Label
                            htmlFor="schedule-cycle"
                            className="mb-1.5 block"
                        >
                            Cycle length (days)
                        </Label>
                        <Input
                            id="schedule-cycle"
                            type="number"
                            min="1"
                            max={MAX_CYCLE_LENGTH}
                            inputMode="numeric"
                            value={data.cycle_length_days}
                            onChange={(e) =>
                                setCycleLength(Number(e.target.value))
                            }
                        />
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            7 repeats weekly. 8 makes a four-on, four-off
                            rotation.
                        </p>
                        <InputError
                            message={errors.cycle_length_days}
                            className="mt-1.5"
                        />
                    </div>

                    {policies.length > 0 && (
                        <div className="sm:col-span-2">
                            <Label
                                htmlFor="schedule-policy"
                                className="mb-1.5 block"
                            >
                                Attendance policy
                            </Label>
                            <FormSelect
                                id="schedule-policy"
                                value={data.attendance_policy_id}
                                onChange={(value) =>
                                    setData('attendance_policy_id', value)
                                }
                                options={[
                                    {
                                        value: NO_POLICY,
                                        label: 'The department’s, or the company default',
                                    },
                                    ...policies.map((policy) => ({
                                        value: String(policy.id),
                                        label: policy.is_default
                                            ? `${policy.name} (company default)`
                                            : policy.name,
                                    })),
                                ]}
                                className="w-full sm:max-w-sm"
                            />
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                How days on this shift are judged — overtime,
                                rounding, night work. Someone’s own assignment
                                can still name a different one.
                            </p>
                            <InputError
                                message={errors.attendance_policy_id}
                                className="mt-1.5"
                            />
                        </div>
                    )}

                    {isRotation && (
                        <div className="sm:col-span-2">
                            <Label
                                htmlFor="schedule-anchor"
                                className="mb-1.5 block"
                            >
                                Cycle starts on
                                <span className="ml-0.5 text-destructive">
                                    *
                                </span>
                            </Label>
                            <Input
                                id="schedule-anchor"
                                type="date"
                                value={data.cycle_anchor_date}
                                onChange={(e) =>
                                    setData('cycle_anchor_date', e.target.value)
                                }
                                className="sm:max-w-xs"
                            />
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                The date Day 1 falls on. Crews sharing this
                                rotation are staggered when you assign them.
                            </p>
                            <InputError
                                message={errors.cycle_anchor_date}
                                className="mt-1.5"
                            />
                        </div>
                    )}
                </div>

                {/* The pattern */}
                <ModalSection
                    title="The pattern"
                    hint={
                        data.type === 'hours_only'
                            ? 'Each day asks for its hours; when they are worked is up to the employee.'
                            : 'Add a second stretch of hours for a split shift. An end before the start crosses midnight.'
                    }
                >
                    <div className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                        {data.days.map((day, index) => (
                            <DayRow
                                key={index}
                                day={day}
                                label={dayLabel(
                                    index + 1,
                                    data.cycle_length_days,
                                )}
                                type={data.type}
                                onChange={(patch) => patchDay(index, patch)}
                            />
                        ))}
                    </div>
                    <InputError message={errors.days} className="mt-1.5" />
                    {Object.entries(errors)
                        .filter(([key]) => key.startsWith('days.'))
                        .slice(0, 3)
                        .map(([key, message]) => (
                            <InputError key={key} message={message} />
                        ))}
                </ModalSection>

                {/* What that means, day by day */}
                <ModalSection
                    title="Next 14 days"
                    hint="How this pattern reads from today, before you save it."
                >
                    <div className="grid grid-cols-7 gap-1.5">
                        {preview.map((day) => (
                            <div
                                key={day.date.toISOString()}
                                className={cn(
                                    'rounded-md border px-1.5 py-2 text-center',
                                    day.isRestDay
                                        ? 'border-border bg-muted/40'
                                        : 'border-[#0ABFBF]/30 bg-[#0ABFBF]/10',
                                    day.isToday && 'ring-1 ring-[#0ABFBF]',
                                )}
                            >
                                <span className="block text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                    {day.date.toLocaleDateString(undefined, {
                                        weekday: 'short',
                                    })}
                                </span>
                                <span className="block text-xs tabular-nums">
                                    {day.date.getDate()}
                                </span>
                                <span
                                    className={cn(
                                        'mt-1 block text-[10px] leading-tight',
                                        day.isRestDay
                                            ? 'text-muted-foreground'
                                            : 'font-medium text-[#0a8b91] dark:text-[#0ABFBF]',
                                    )}
                                >
                                    {day.label}
                                </span>
                            </div>
                        ))}
                    </div>
                </ModalSection>
            </ModalBody>

            <ModalFooter>
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
                    {isEditing ? 'Save changes' : 'Create schedule'}
                </Button>
            </ModalFooter>
        </form>
    );
}

/** One day of the cycle: worked or not, its hours, and what it asks for. */
function DayRow({
    day,
    label,
    type,
    onChange,
}: {
    day: ScheduleDay;
    label: string;
    type: ScheduleType;
    onChange: (patch: Partial<ScheduleDay>) => void;
}) {
    const setSegment = (
        index: number,
        patch: { start?: string; end?: string },
    ) =>
        onChange({
            segments: day.segments.map((segment, i) =>
                i === index ? { ...segment, ...patch } : segment,
            ),
        });

    const addSegment = () =>
        onChange({ segments: [...day.segments, { ...DEFAULT_SEGMENT }] });

    const removeSegment = (index: number) =>
        onChange({ segments: day.segments.filter((_, i) => i !== index) });

    const toggleRestDay = (isRestDay: boolean) =>
        onChange({
            is_rest_day: isRestDay,
            segments: isRestDay ? [] : [{ ...DEFAULT_SEGMENT }],
            required_minutes: isRestDay ? 0 : day.required_minutes || 480,
        });

    return (
        <div
            className={cn(
                'flex flex-col gap-3 px-3 py-3 sm:flex-row sm:items-start',
                day.is_rest_day && 'bg-muted/30',
            )}
        >
            <div className="flex items-center gap-2.5 sm:w-32 sm:shrink-0 sm:pt-1.5">
                <Switch
                    checked={!day.is_rest_day}
                    onCheckedChange={(checked) => toggleRestDay(!checked)}
                    aria-label={`${label} is a working day`}
                />
                <span
                    className={cn(
                        'text-sm font-medium',
                        day.is_rest_day && 'text-muted-foreground',
                    )}
                >
                    {label}
                </span>
            </div>

            {day.is_rest_day ? (
                <p className="text-sm text-muted-foreground sm:pt-1.5">
                    Rest day
                </p>
            ) : (
                <div className="min-w-0 flex-1 space-y-2">
                    {type !== 'hours_only' &&
                        day.segments.map((segment, index) => (
                            <div
                                key={index}
                                className="flex items-center gap-2"
                            >
                                <Input
                                    type="time"
                                    value={segment.start}
                                    onChange={(e) =>
                                        setSegment(index, {
                                            start: e.target.value,
                                        })
                                    }
                                    aria-label={`${label} start time`}
                                    className="min-w-0 flex-1 sm:w-32 sm:flex-none"
                                />
                                <span className="text-muted-foreground">–</span>
                                <Input
                                    type="time"
                                    value={segment.end}
                                    onChange={(e) =>
                                        setSegment(index, {
                                            end: e.target.value,
                                        })
                                    }
                                    aria-label={`${label} end time`}
                                    className="min-w-0 flex-1 sm:w-32 sm:flex-none"
                                />
                                {day.segments.length > 1 && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => removeSegment(index)}
                                        aria-label="Remove these hours"
                                    >
                                        <X className="size-4" />
                                    </Button>
                                )}
                            </div>
                        ))}

                    <div className="flex flex-wrap items-center gap-2">
                        {type !== 'hours_only' && day.segments.length < 4 && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={addSegment}
                                className="h-8 px-2 text-xs"
                            >
                                <Plus className="size-3.5" />
                                Split the shift
                            </Button>
                        )}
                        <label className="flex items-center gap-2 text-xs text-muted-foreground">
                            Requires
                            <Input
                                type="number"
                                min="0"
                                max="1440"
                                step="15"
                                inputMode="numeric"
                                value={day.required_minutes}
                                onChange={(e) =>
                                    onChange({
                                        required_minutes: Number(
                                            e.target.value,
                                        ),
                                    })
                                }
                                aria-label={`${label} required minutes`}
                                className="h-8 w-24"
                            />
                            <span className="tabular-nums">
                                {formatMinutes(day.required_minutes)}
                            </span>
                            {type !== 'hours_only' &&
                                day.segments.length > 0 &&
                                segmentMinutes(day.segments) !==
                                    day.required_minutes && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            onChange({
                                                required_minutes:
                                                    segmentMinutes(
                                                        day.segments,
                                                    ),
                                            })
                                        }
                                        className="text-[#0a8b91] underline-offset-2 hover:underline dark:text-[#0ABFBF]"
                                    >
                                        match the hours (
                                        {formatMinutes(
                                            segmentMinutes(day.segments),
                                        )}
                                        )
                                    </button>
                                )}
                        </label>
                    </div>

                    {type === 'flexible' && (
                        <div className="flex flex-wrap items-center gap-2 rounded-md bg-muted/50 px-2.5 py-2">
                            <CalendarRange className="size-3.5 text-muted-foreground" />
                            <span className="text-xs text-muted-foreground">
                                Core hours
                            </span>
                            <Input
                                type="time"
                                value={day.core_start ?? ''}
                                onChange={(e) =>
                                    onChange({
                                        core_start: e.target.value || null,
                                    })
                                }
                                aria-label={`${label} core start`}
                                className="h-8 min-w-0 flex-1 sm:w-28 sm:flex-none"
                            />
                            <span className="text-muted-foreground">–</span>
                            <Input
                                type="time"
                                value={day.core_end ?? ''}
                                onChange={(e) =>
                                    onChange({
                                        core_end: e.target.value || null,
                                    })
                                }
                                aria-label={`${label} core end`}
                                className="h-8 min-w-0 flex-1 sm:w-28 sm:flex-none"
                            />
                            <span className="text-xs text-muted-foreground">
                                — everyone must be present for this
                            </span>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
