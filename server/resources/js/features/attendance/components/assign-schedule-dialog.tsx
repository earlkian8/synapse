import { useForm } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
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
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import type { EmployeeOption, ScheduleRef } from '../types';

type Props = {
    /** Everyone the assignment covers — one person from a profile, a week's roster from the board. */
    employees: { id: number; full_name: string }[] | EmployeeOption[];
    schedules: ScheduleRef[];
    /** Where to post. The profile posts to its own employee-scoped endpoint. */
    action: string;
    /** Omitted when the URL already names the employee. */
    sendEmployeeIds?: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Put people on a schedule from a date (ADR 0037).
 *
 * The date is the point of it: an assignment starting next Monday leaves every
 * day before it judged by the shift they were actually on. Whatever was in force
 * is closed the day before, so the two never overlap.
 */
export function AssignScheduleDialog({
    employees,
    schedules,
    action,
    sendEmployeeIds = true,
    open,
    onOpenChange,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <CalendarClock />
                        </ModalIcon>
                    }
                    title="Assign a schedule"
                    description={
                        employees.length === 1
                            ? `${employees[0].full_name} works this shift from the date you choose.`
                            : `${employees.length} people work this shift from the date you choose.`
                    }
                />

                {open && (
                    <FormBody
                        employees={employees}
                        schedules={schedules}
                        action={action}
                        sendEmployeeIds={sendEmployeeIds}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    employees,
    schedules,
    action,
    sendEmployeeIds,
    onDone,
}: Omit<Props, 'open' | 'onOpenChange'> & { onDone: () => void }) {
    const [bounded, setBounded] = useState(false);

    const { data, setData, post, transform, processing, errors } = useForm({
        employee_ids: employees.map((employee) => employee.id),
        work_schedule_id: '',
        effective_from: today(),
        effective_to: '',
        cycle_offset: 0,
    });

    const schedule = schedules.find(
        (option) => String(option.id) === data.work_schedule_id,
    );
    const isRotation =
        schedule !== undefined && schedule.cycle_length_days !== 7;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...(sendEmployeeIds ? { employee_ids: payload.employee_ids } : {}),
            work_schedule_id: payload.work_schedule_id,
            effective_from: payload.effective_from,
            effective_to: bounded ? payload.effective_to || null : null,
            cycle_offset: isRotation ? payload.cycle_offset : 0,
        }));

        post(action, { preserveScroll: true, onSuccess: () => onDone() });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <div>
                    <Label htmlFor="assign-schedule" className="mb-1.5 block">
                        Shift
                        <span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <FormSelect
                        id="assign-schedule"
                        value={data.work_schedule_id}
                        onChange={(value) => setData('work_schedule_id', value)}
                        options={schedules.map((option) => ({
                            value: String(option.id),
                            label: option.name,
                        }))}
                        placeholder="Choose a shift…"
                    />
                    <InputError
                        message={errors.work_schedule_id}
                        className="mt-1.5"
                    />
                </div>

                <div>
                    <Label htmlFor="assign-from" className="mb-1.5 block">
                        Starting
                        <span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Input
                        id="assign-from"
                        type="date"
                        value={data.effective_from}
                        onChange={(e) =>
                            setData('effective_from', e.target.value)
                        }
                        required
                    />
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        Days before this keep the shift they were recorded
                        against.
                    </p>
                    <InputError
                        message={errors.effective_from}
                        className="mt-1.5"
                    />
                </div>

                <div className="space-y-3 rounded-lg border border-border px-3 py-3">
                    <label className="flex items-center gap-2.5">
                        <Switch
                            checked={bounded}
                            onCheckedChange={setBounded}
                        />
                        <span className="text-sm">
                            Only for a while
                            <span className="ml-1.5 text-xs text-muted-foreground">
                                — they go back to their old shift afterwards
                            </span>
                        </span>
                    </label>

                    {bounded && (
                        <div>
                            <Label
                                htmlFor="assign-to"
                                className="mb-1.5 block text-xs"
                            >
                                Until
                            </Label>
                            <Input
                                id="assign-to"
                                type="date"
                                value={data.effective_to}
                                min={data.effective_from}
                                onChange={(e) =>
                                    setData('effective_to', e.target.value)
                                }
                            />
                            <InputError
                                message={errors.effective_to}
                                className="mt-1.5"
                            />
                        </div>
                    )}
                </div>

                {isRotation && (
                    <div>
                        <Label htmlFor="assign-offset" className="mb-1.5 block">
                            Starts on day
                        </Label>
                        <Input
                            id="assign-offset"
                            type="number"
                            min="0"
                            max={schedule.cycle_length_days - 1}
                            inputMode="numeric"
                            value={data.cycle_offset}
                            onChange={(e) =>
                                setData('cycle_offset', Number(e.target.value))
                            }
                            className="sm:max-w-[10rem]"
                        />
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            Where in the {schedule.cycle_length_days}-day
                            rotation this crew begins. Two crews four apart on a
                            four-on, four-off shift never work the same day.
                        </p>
                        <InputError
                            message={errors.cycle_offset}
                            className="mt-1.5"
                        />
                    </div>
                )}

                <InputError message={errors.employee_ids} />
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
                <Button
                    type="submit"
                    disabled={processing || !data.work_schedule_id}
                >
                    {processing && <Spinner />}
                    Assign
                </Button>
            </ModalFooter>
        </form>
    );
}

/** Today as "Y-m-d" in the browser's own calendar — the sensible starting date. */
function today(): string {
    const now = new Date();

    return [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
    ].join('-');
}
