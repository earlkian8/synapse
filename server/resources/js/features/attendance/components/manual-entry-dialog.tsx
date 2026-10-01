import { router } from '@inertiajs/react';
import { Clock3 } from 'lucide-react';
import { useEffect, useState } from 'react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import { clockReading, todayIn } from '../constants';
import { attendanceRoutes } from '../routes';
import type {
    AttendanceRecord,
    EmployeeOption,
    Punch,
    PunchType,
} from '../types';

type Props = {
    record: AttendanceRecord | null;
    employees: EmployeeOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type Times = {
    time_in: string;
    break_start: string;
    break_end: string;
    time_out: string;
};

const EMPTY_TIMES: Times = {
    time_in: '',
    break_start: '',
    break_end: '',
    time_out: '',
};

const PUNCH_TO_FIELD: Record<PunchType, keyof Times> = {
    clock_in: 'time_in',
    break_start: 'break_start',
    break_end: 'break_end',
    clock_out: 'time_out',
};

/** A work date in the words the rest of the module uses. */
function formatDay(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

/**
 * Recording a day by hand, or correcting one the clock got wrong.
 *
 * The four punches are the form's substance, so they sit on one row from `sm`
 * up — the order they happen in, readable in a glance — rather than stacked two
 * by two down a narrow strip.
 */
export function ManualEntryDialog({
    record,
    employees,
    open,
    onOpenChange,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                {open && (
                    <Body
                        key={record?.hashid ?? record?.employee?.id ?? 'new'}
                        record={record}
                        employees={employees}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function Body({
    record,
    employees,
    onDone,
}: {
    record: AttendanceRecord | null;
    employees: EmployeeOption[];
    onDone: () => void;
}) {
    const isEdit = Boolean(record?.hashid);
    const lockedEmployee = record?.employee ?? null;
    // Times are read and written on the organisation's clock — the server reads
    // "08:00" back as 08:00 there, whatever zone this browser is in.
    const timeZone = useOrganizationTimeZone();
    const today = todayIn(timeZone);

    const [employeeId, setEmployeeId] = useState<string>(
        lockedEmployee ? String(lockedEmployee.id) : '',
    );
    const [date, setDate] = useState<string>(record?.work_date ?? today);
    const [times, setTimes] = useState<Times>(() => ({
        ...EMPTY_TIMES,
        time_in: clockReading(record?.first_in_at ?? null, timeZone),
        time_out: clockReading(record?.last_out_at ?? null, timeZone),
    }));
    const [remarks, setRemarks] = useState(record?.remarks ?? '');
    const [processing, setProcessing] = useState(false);

    // When editing, pull the full punch set to prefill break times too.
    useEffect(() => {
        if (!record?.hashid) {
            return;
        }

        let active = true;

        fetch(attendanceRoutes.show(record.hashid), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((payload) => {
                if (!active || !payload?.data) {
                    return;
                }

                const next = { ...EMPTY_TIMES };
                (payload.data.punches ?? []).forEach((punch: Punch) => {
                    next[PUNCH_TO_FIELD[punch.type]] = clockReading(
                        punch.punched_at,
                        timeZone,
                    );
                });
                setTimes(next);
                setRemarks(payload.data.remarks ?? '');
            })
            .catch(() => undefined);

        return () => {
            active = false;
        };
    }, [record, timeZone]);

    const setField = (field: keyof Times, value: string) =>
        setTimes((prev) => ({ ...prev, [field]: value }));

    const submit = () => {
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onDone(),
        };

        if (isEdit && record?.hashid) {
            router.post(
                attendanceRoutes.update(record.hashid),
                { ...times, remarks: remarks || null },
                options,
            );
        } else {
            router.post(
                attendanceRoutes.store,
                {
                    employee_id: Number(employeeId),
                    work_date: date,
                    ...times,
                    remarks: remarks || null,
                },
                options,
            );
        }
    };

    const canSubmit = isEdit || (employeeId !== '' && date !== '');
    const needsSubject = !lockedEmployee;

    return (
        <>
            <ModalHeader
                icon={
                    <ModalIcon>
                        <Clock3 />
                    </ModalIcon>
                }
                title={isEdit ? 'Correct attendance' : 'Record attendance'}
                description={
                    lockedEmployee
                        ? `${lockedEmployee.full_name}${record?.work_date ? ` · ${formatDay(record.work_date)}` : ''}`
                        : 'Enter a day the clock missed.'
                }
            />

            <ModalBody className="space-y-4 py-4">
                {needsSubject && (
                    <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_11rem]">
                        <div className="space-y-1.5">
                            <Label htmlFor="manual-employee">Employee</Label>
                            <Select
                                value={employeeId}
                                onValueChange={setEmployeeId}
                            >
                                <SelectTrigger
                                    id="manual-employee"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Select an employee" />
                                </SelectTrigger>
                                <SelectContent>
                                    {employees.map((employee) => (
                                        <SelectItem
                                            key={employee.id}
                                            value={String(employee.id)}
                                        >
                                            {employee.full_name} ·{' '}
                                            {employee.employee_no}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {!isEdit && (
                            <div className="space-y-1.5">
                                <Label htmlFor="manual-date">Date</Label>
                                <Input
                                    id="manual-date"
                                    type="date"
                                    value={date}
                                    max={today}
                                    onChange={(event) =>
                                        setDate(event.target.value)
                                    }
                                />
                            </div>
                        )}
                    </div>
                )}

                <div className="space-y-2">
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <TimeField
                            id="manual-time-in"
                            label="Time in"
                            value={times.time_in}
                            onChange={(v) => setField('time_in', v)}
                        />
                        <TimeField
                            id="manual-break-start"
                            label="Break start"
                            value={times.break_start}
                            onChange={(v) => setField('break_start', v)}
                        />
                        <TimeField
                            id="manual-break-end"
                            label="Break end"
                            value={times.break_end}
                            onChange={(v) => setField('break_end', v)}
                        />
                        <TimeField
                            id="manual-time-out"
                            label="Time out"
                            value={times.time_out}
                            onChange={(v) => setField('time_out', v)}
                        />
                    </div>
                    <Preview times={times} />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="manual-remarks">
                        Remarks{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </Label>
                    <textarea
                        id="manual-remarks"
                        value={remarks}
                        onChange={(event) => setRemarks(event.target.value)}
                        rows={2}
                        placeholder="Reason for the manual entry / correction…"
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                </div>
            </ModalBody>

            <ModalFooter>
                <Button variant="ghost" onClick={onDone} disabled={processing}>
                    Cancel
                </Button>
                <Button onClick={submit} disabled={!canSubmit || processing}>
                    {processing && <Spinner />}
                    {isEdit ? 'Save correction' : 'Save record'}
                </Button>
            </ModalFooter>
        </>
    );
}

/**
 * What the four times add up to, before anything is saved.
 *
 * The form takes clock readings and the server turns them into a day, so the
 * one thing it cannot show you is the thing you are actually recording. This
 * does the same subtraction the record will: out minus in, less the break.
 * Lateness and overtime stay the server's — they need the employee's schedule,
 * which this form does not have.
 */
function Preview({ times }: { times: Times }) {
    const worked = minutesBetween(times.time_in, times.time_out);
    const brk = minutesBetween(times.break_start, times.break_end);
    const net = worked === null ? null : Math.max(worked - (brk ?? 0), 0);

    return (
        <p className="rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
            {net === null ? (
                'Leave the times blank to mark the day absent. Lateness and overtime are worked out against the employee\u2019s schedule.'
            ) : (
                <>
                    <span className="font-semibold text-foreground tabular-nums">
                        {formatSpan(net)}
                    </span>{' '}
                    worked
                    {brk ? (
                        <>
                            {' '}
                            after a{' '}
                            <span className="tabular-nums">
                                {formatSpan(brk)}
                            </span>{' '}
                            break
                        </>
                    ) : null}
                    . Lateness and overtime are worked out against the
                    employee&apos;s schedule.
                </>
            )}
        </p>
    );
}

/** Minutes between two `HH:MM` readings, or null when the pair is unusable. */
function minutesBetween(from: string, to: string): number | null {
    if (!from || !to) {
        return null;
    }

    const [fromH, fromM] = from.split(':').map(Number);
    const [toH, toM] = to.split(':').map(Number);
    const minutes = toH * 60 + toM - (fromH * 60 + fromM);

    // A shift that ends before it starts ran past midnight.
    return minutes < 0 ? minutes + 24 * 60 : minutes;
}

function formatSpan(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return `${rest}m`;
    }

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

function TimeField({
    id,
    label,
    value,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                type="time"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </div>
    );
}
