import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { DEFAULT_WORK_DAYS, WEEK_DAYS } from '../constants';
import { scheduleConfigRoutes } from '../routes';
import type { WeekDay, WorkSchedule } from '../types';

type Props = {
    schedule: WorkSchedule | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function WorkScheduleFormSheet({ schedule, open, onOpenChange }: Props) {
    const isEditing = Boolean(schedule);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit work schedule' : 'New work schedule'}
                    </SheetTitle>
                    <SheetDescription>
                        A shift pattern employees are assigned to — its hours,
                        working days and lateness grace.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={schedule?.id ?? 'new'}
                        schedule={schedule}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    schedule,
    onDone,
}: {
    schedule: WorkSchedule | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(schedule);

    const { data, setData, post, processing, errors } = useForm({
        name: schedule?.name ?? '',
        start_time: schedule?.start_time ?? '08:00',
        end_time: schedule?.end_time ?? '17:00',
        work_days: schedule?.work_days ?? DEFAULT_WORK_DAYS,
        grace_minutes: schedule?.grace_minutes ?? 0,
        required_hours: schedule?.required_hours ?? 8,
    });

    const toggleDay = (day: WeekDay) => {
        const next = data.work_days.includes(day)
            ? data.work_days.filter((d) => d !== day)
            : WEEK_DAYS.filter((d) => d === day || data.work_days.includes(d));
        setData('work_days', next);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && schedule) {
            post(
                scheduleConfigRoutes.workSchedules.update(schedule.hashid),
                opts,
            );
        } else {
            post(scheduleConfigRoutes.workSchedules.store, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div>
                    <Label className="mb-1.5 block">
                        Name<span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="e.g. Day Shift"
                        required
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <Label className="mb-1.5 block">Start time</Label>
                        <Input
                            type="time"
                            value={data.start_time ?? ''}
                            onChange={(e) =>
                                setData('start_time', e.target.value)
                            }
                        />
                        <InputError
                            message={errors.start_time}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label className="mb-1.5 block">End time</Label>
                        <Input
                            type="time"
                            value={data.end_time ?? ''}
                            onChange={(e) =>
                                setData('end_time', e.target.value)
                            }
                        />
                        <InputError
                            message={errors.end_time}
                            className="mt-1.5"
                        />
                    </div>
                </div>

                <div>
                    <Label className="mb-1.5 block">Working days</Label>
                    <div className="flex flex-wrap gap-1.5">
                        {WEEK_DAYS.map((day) => {
                            const active = data.work_days.includes(day);

                            return (
                                <button
                                    key={day}
                                    type="button"
                                    onClick={() => toggleDay(day)}
                                    aria-pressed={active}
                                    className={cn(
                                        'h-9 w-11 rounded-md border text-xs font-medium transition-colors',
                                        active
                                            ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 text-[#0a8b91] dark:text-[#0ABFBF]'
                                            : 'border-input text-muted-foreground hover:bg-muted',
                                    )}
                                >
                                    {day}
                                </button>
                            );
                        })}
                    </div>
                    <InputError message={errors.work_days} className="mt-1.5" />
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <Label className="mb-1.5 block">
                            Grace (minutes)
                            <span className="ml-0.5 text-destructive">*</span>
                        </Label>
                        <Input
                            type="number"
                            min="0"
                            max="240"
                            inputMode="numeric"
                            value={data.grace_minutes}
                            onChange={(e) =>
                                setData('grace_minutes', Number(e.target.value))
                            }
                        />
                        <InputError
                            message={errors.grace_minutes}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label className="mb-1.5 block">
                            Required hours
                            <span className="ml-0.5 text-destructive">*</span>
                        </Label>
                        <Input
                            type="number"
                            min="0"
                            max="24"
                            step="0.5"
                            inputMode="decimal"
                            value={data.required_hours}
                            onChange={(e) =>
                                setData(
                                    'required_hours',
                                    Number(e.target.value),
                                )
                            }
                        />
                        <InputError
                            message={errors.required_hours}
                            className="mt-1.5"
                        />
                    </div>
                </div>
                <p className="text-xs text-muted-foreground">
                    Lateness grace and required hours drive how Attendance flags
                    late and undertime days. An end before the start means an
                    overnight shift.
                </p>
            </div>

            <SheetFooter className="border-t border-border px-6 py-4">
                <div className="flex w-full items-center justify-end gap-2">
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
                        {isEditing ? 'Save changes' : 'Create'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}
