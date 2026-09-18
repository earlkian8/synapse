import { router } from '@inertiajs/react';
import { FilePlus2 } from 'lucide-react';
import { useState } from 'react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import { cn } from '@/lib/utils';
import {
    CORRECTION_FIELDS,
    clockReading,
    formatClockFace,
    REQUEST_TYPE_HINTS,
    REQUEST_TYPE_LABELS,
    todayIn,
} from '../constants';
import { attendanceRoutes } from '../routes';
import type {
    AttendanceRecord,
    AttendanceRequestType,
    EmployeeOption,
    Punch,
} from '../types';

/** What the dialog opens on: a type, a day, and the day's record when there is one. */
export type RequestDraft = {
    type: AttendanceRequestType;
    date?: string;
    record?: AttendanceRecord | null;
};

type Props = {
    draft: RequestDraft | null;
    /** Given, the dialog files on somebody's behalf (HR, ADR 0039). */
    employees?: EmployeeOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const TYPES: AttendanceRequestType[] = [
    'correction',
    'overtime',
    'official_business',
    'remote_work',
];

/**
 * Asking attendance for something the clock could not capture (ADR 0039).
 *
 * One dialog for the four kinds, because they are one decision for the person
 * filing — "what do I need?" — so the kind is the first thing chosen and the
 * rest of the form follows it. A correction shows what the day has recorded
 * beside each time, since the most common correction is one missing punch and
 * the rest should be left as they are.
 */
export function FileRequestDialog({
    draft,
    employees,
    open,
    onOpenChange,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                {open && draft && (
                    <Body
                        key={`${draft.type}-${draft.date ?? ''}-${draft.record?.hashid ?? ''}`}
                        draft={draft}
                        employees={employees}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

type Errors = Partial<Record<string, string>>;

function Body({
    draft,
    employees,
    onDone,
}: {
    draft: RequestDraft;
    employees?: EmployeeOption[];
    onDone: () => void;
}) {
    const timeZone = useOrganizationTimeZone();
    const today = todayIn(timeZone);
    const recorded = recordedTimes(draft.record?.punches ?? [], timeZone);

    const [type, setType] = useState<AttendanceRequestType>(draft.type);
    const [employeeId, setEmployeeId] = useState('');
    const [start, setStart] = useState(draft.date ?? today);
    const [end, setEnd] = useState(draft.date ?? today);
    const [times, setTimes] = useState<Record<string, string>>({});
    const [hours, setHours] = useState('');
    const [minutes, setMinutes] = useState('');
    const [hoursWindow, setHoursWindow] = useState({ start: '', end: '' });
    const [location, setLocation] = useState('');
    const [reason, setReason] = useState('');
    const [attachment, setAttachment] = useState<File | null>(null);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);

    const isRange = type === 'official_business' || type === 'remote_work';
    const askedMinutes = Number(hours || 0) * 60 + Number(minutes || 0);

    const submit = () => {
        const payload: Record<string, string | number | File> = {
            type,
            start_date: start,
            reason,
        };

        if (employees && employeeId) {
            payload.employee_id = Number(employeeId);
        }

        if (isRange) {
            payload.end_date = end;

            if (hoursWindow.start) {
                payload.start_time = hoursWindow.start;
            }

            if (hoursWindow.end) {
                payload.end_time = hoursWindow.end;
            }

            if (location) {
                payload.location = location;
            }
        }

        if (type === 'correction') {
            CORRECTION_FIELDS.forEach(({ key }) => {
                if (times[key]) {
                    payload[key] = times[key];
                }
            });
        }

        if (type === 'overtime' && askedMinutes > 0) {
            payload.minutes = askedMinutes;
        }

        if (attachment) {
            payload.attachment = attachment;
        }

        // A refusal the server explains — a locked period, a request already
        // waiting — comes back as a warning toast, not a validation error; the
        // dialog stays open for it as it does for an error.
        let refused = false;

        router.post(attendanceRoutes.requestStore, payload, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onError: (next) => {
                refused = true;
                setErrors(next);
            },
            onFlash: (flash) => {
                const toast = (flash as { toast?: { type?: string } }).toast;
                refused = refused || toast?.type === 'warning';
            },
            onFinish: () => {
                setProcessing(false);

                if (!refused) {
                    onDone();
                }
            },
        });
    };

    const canSubmit =
        reason.trim().length >= 3 &&
        start !== '' &&
        (!employees || employeeId !== '') &&
        (type !== 'overtime' || askedMinutes > 0) &&
        (type !== 'correction' ||
            CORRECTION_FIELDS.some(({ key }) => times[key]));

    return (
        <>
            <ModalHeader
                icon={
                    <ModalIcon>
                        <FilePlus2 />
                    </ModalIcon>
                }
                title="Ask for an attendance change"
                description={
                    employees
                        ? 'Filed on the employee’s behalf. A reviewer decides it.'
                        : 'A reviewer decides it, and you are told either way.'
                }
            />

            <ModalBody className="space-y-5 py-4">
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        What do you need?
                    </legend>
                    <div
                        className="grid gap-2 sm:grid-cols-2"
                        role="radiogroup"
                    >
                        {TYPES.map((value) => {
                            const active = value === type;

                            return (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={active}
                                    onClick={() => setType(value)}
                                    className={cn(
                                        'rounded-lg border px-3 py-2 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none',
                                        active
                                            ? 'border-[#0ABFBF] bg-[#0ABFBF]/[0.07]'
                                            : 'border-border hover:bg-muted/50',
                                    )}
                                >
                                    <span className="block text-sm font-medium">
                                        {REQUEST_TYPE_LABELS[value]}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {REQUEST_TYPE_HINTS[value]}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <InputError message={errors.type} />
                </fieldset>

                <div
                    className={cn(
                        'grid gap-4',
                        employees ? 'sm:grid-cols-[minmax(0,1fr)_auto]' : '',
                    )}
                >
                    {employees && (
                        <div className="space-y-1.5">
                            <Label htmlFor="request-employee">Employee</Label>
                            <Select
                                value={employeeId}
                                onValueChange={setEmployeeId}
                            >
                                <SelectTrigger
                                    id="request-employee"
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
                            <InputError message={errors.employee_id} />
                        </div>
                    )}

                    <div
                        className={cn(
                            'grid gap-3',
                            isRange ? 'grid-cols-2' : 'grid-cols-1',
                        )}
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor="request-start">
                                {isRange ? 'From' : 'Day'}
                            </Label>
                            <Input
                                id="request-start"
                                type="date"
                                value={start}
                                max={type === 'correction' ? today : undefined}
                                onChange={(event) => {
                                    setStart(event.target.value);

                                    if (end < event.target.value) {
                                        setEnd(event.target.value);
                                    }
                                }}
                            />
                            <InputError message={errors.start_date} />
                        </div>
                        {isRange && (
                            <div className="space-y-1.5">
                                <Label htmlFor="request-end">To</Label>
                                <Input
                                    id="request-end"
                                    type="date"
                                    value={end}
                                    min={start}
                                    onChange={(event) =>
                                        setEnd(event.target.value)
                                    }
                                />
                                <InputError message={errors.end_date} />
                            </div>
                        )}
                    </div>
                </div>

                {type === 'correction' && (
                    <div className="space-y-2">
                        <p className="text-sm font-medium">
                            The times the day should show
                        </p>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {CORRECTION_FIELDS.map(({ key, label, punch }) => (
                                <div key={key} className="space-y-1.5">
                                    <Label htmlFor={`request-${key}`}>
                                        {label}
                                    </Label>
                                    <Input
                                        id={`request-${key}`}
                                        type="time"
                                        value={times[key] ?? ''}
                                        onChange={(event) =>
                                            setTimes((prev) => ({
                                                ...prev,
                                                [key]: event.target.value,
                                            }))
                                        }
                                    />
                                    <p className="text-[11px] text-muted-foreground">
                                        {draft.record && draft.date === start
                                            ? recorded[punch]
                                                ? `Recorded ${formatClockFace(recorded[punch])}`
                                                : 'Not punched'
                                            : ' '}
                                    </p>
                                    <InputError message={errors[key]} />
                                </div>
                            ))}
                        </div>
                        <p className="rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                            Fill in only what should change. A time left blank
                            keeps the punch the day already has.
                        </p>
                    </div>
                )}

                {type === 'overtime' && (
                    <div className="space-y-1.5">
                        <Label htmlFor="request-hours">How much overtime</Label>
                        <div className="flex items-center gap-2">
                            <Input
                                id="request-hours"
                                type="number"
                                min={0}
                                max={16}
                                inputMode="numeric"
                                value={hours}
                                onChange={(event) =>
                                    setHours(event.target.value)
                                }
                                className="w-20"
                                aria-label="Hours"
                            />
                            <span className="text-sm text-muted-foreground">
                                h
                            </span>
                            <Input
                                type="number"
                                min={0}
                                max={59}
                                step={5}
                                inputMode="numeric"
                                value={minutes}
                                onChange={(event) =>
                                    setMinutes(event.target.value)
                                }
                                className="w-20"
                                aria-label="Minutes"
                            />
                            <span className="text-sm text-muted-foreground">
                                min
                            </span>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {start > today
                                ? 'Asked in advance: approved, it counts once the day is worked.'
                                : 'Approved overtime is never more than was actually worked past the shift.'}
                        </p>
                        <InputError message={errors.minutes} />
                    </div>
                )}

                {isRange && (
                    <div className="grid gap-4 sm:grid-cols-[auto_minmax(0,1fr)]">
                        <div className="space-y-1.5">
                            <Label htmlFor="request-window-start">
                                Hours{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="request-window-start"
                                    type="time"
                                    value={hoursWindow.start}
                                    onChange={(event) =>
                                        setHoursWindow((prev) => ({
                                            ...prev,
                                            start: event.target.value,
                                        }))
                                    }
                                    aria-label="From"
                                />
                                <span className="text-muted-foreground">–</span>
                                <Input
                                    type="time"
                                    value={hoursWindow.end}
                                    onChange={(event) =>
                                        setHoursWindow((prev) => ({
                                            ...prev,
                                            end: event.target.value,
                                        }))
                                    }
                                    aria-label="Until"
                                />
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="request-location">
                                Where{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Input
                                id="request-location"
                                value={location}
                                maxLength={255}
                                placeholder={
                                    type === 'official_business'
                                        ? 'Client office, Makati'
                                        : 'Home'
                                }
                                onChange={(event) =>
                                    setLocation(event.target.value)
                                }
                            />
                            <InputError message={errors.location} />
                        </div>
                    </div>
                )}

                <div className="space-y-1.5">
                    <Label htmlFor="request-reason">Why</Label>
                    <textarea
                        id="request-reason"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        rows={2}
                        maxLength={2000}
                        placeholder={
                            type === 'correction'
                                ? 'Forgot to clock out after the late meeting.'
                                : 'What the reviewer needs to know.'
                        }
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                    <InputError message={errors.reason} />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="request-attachment">
                        Attachment{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional — a memo, a photo; PDF or image, up to 5
                            MB)
                        </span>
                    </Label>
                    <Input
                        id="request-attachment"
                        type="file"
                        accept=".pdf,image/jpeg,image/png,image/webp"
                        onChange={(event) =>
                            setAttachment(event.target.files?.[0] ?? null)
                        }
                    />
                    <InputError message={errors.attachment} />
                </div>
            </ModalBody>

            <ModalFooter>
                <Button variant="ghost" onClick={onDone} disabled={processing}>
                    Cancel
                </Button>
                <Button onClick={submit} disabled={!canSubmit || processing}>
                    {processing && <Spinner />}
                    Send for review
                </Button>
            </ModalFooter>
        </>
    );
}

/** The punch each correction field compares with, as "HH:MM" on the organisation's clock. */
function recordedTimes(
    punches: Punch[],
    timeZone: string | undefined,
): Partial<Record<Punch['type'], string>> {
    const out: Partial<Record<Punch['type'], string>> = {};

    for (const punch of punches) {
        if (punch.type === 'clock_out' || !out[punch.type]) {
            out[punch.type] = clockReading(punch.punched_at, timeZone);
        }
    }

    return out;
}
