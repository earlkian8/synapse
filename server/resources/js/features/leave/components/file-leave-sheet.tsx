import { useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { leaveRoutes } from '../routes';
import type { EmployeeOption, LeaveRequest, LeaveTypeRef } from '../types';

type Props = {
    request: LeaveRequest | null;
    employees: EmployeeOption[];
    types: LeaveTypeRef[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const inputClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:opacity-50';

const today = () => new Date().toISOString().slice(0, 10);

export function FileLeaveSheet({
    request,
    employees,
    types,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(request);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit leave request' : 'File leave'}
                    </SheetTitle>
                    <SheetDescription>
                        {isEditing
                            ? 'Update this pending request.'
                            : 'Record time off for an employee.'}
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={request?.id ?? 'new'}
                        request={request}
                        employees={employees}
                        types={types}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    request,
    employees,
    types,
    onDone,
}: {
    request: LeaveRequest | null;
    employees: EmployeeOption[];
    types: LeaveTypeRef[];
    onDone: () => void;
}) {
    const isEditing = Boolean(request);

    const { data, setData, post, processing, errors, transform } = useForm({
        employee_id: request?.employee ? String(request.employee.id) : '',
        leave_type_id: request?.type ? String(request.type.id) : '',
        start_date: request?.start_date ?? today(),
        end_date: request?.end_date ?? today(),
        is_half_day: request?.is_half_day ?? false,
        half_day_period: request?.half_day_period ?? 'morning',
        reason: request?.reason ?? '',
    });

    const selectedType = useMemo(
        () => types.find((t) => String(t.id) === data.leave_type_id),
        [types, data.leave_type_id],
    );

    const singleDay = data.start_date === data.end_date;
    const canHalfDay = singleDay && (selectedType?.allow_half_day ?? false);
    const isHalfDay = canHalfDay && data.is_half_day;
    const estimate = isHalfDay
        ? 0.5
        : workingDays(data.start_date, data.end_date);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            employee_id: payload.employee_id
                ? Number(payload.employee_id)
                : null,
            leave_type_id: payload.leave_type_id
                ? Number(payload.leave_type_id)
                : null,
            start_date: payload.start_date,
            end_date: payload.end_date,
            is_half_day: canHalfDay ? payload.is_half_day : false,
            half_day_period:
                canHalfDay && payload.is_half_day
                    ? payload.half_day_period
                    : null,
            reason: payload.reason || null,
        }));

        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && request) {
            post(leaveRoutes.update(request.hashid), opts);
        } else {
            post(leaveRoutes.store, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <Field label="Employee" required error={errors.employee_id}>
                    <Select
                        value={data.employee_id}
                        onValueChange={(v) => setData('employee_id', v)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Select an employee…" />
                        </SelectTrigger>
                        <SelectContent>
                            {employees.map((e) => (
                                <SelectItem key={e.id} value={String(e.id)}>
                                    {e.full_name}
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {e.employee_no}
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <Field label="Leave type" required error={errors.leave_type_id}>
                    <Select
                        value={data.leave_type_id}
                        onValueChange={(v) => setData('leave_type_id', v)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Select a type…" />
                        </SelectTrigger>
                        <SelectContent>
                            {types.map((t) => (
                                <SelectItem key={t.id} value={String(t.id)}>
                                    <span
                                        className="mr-1.5 inline-block size-2 rounded-full align-middle"
                                        style={{ backgroundColor: t.color }}
                                    />
                                    {t.name}
                                    {t.is_paid === false && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · Unpaid
                                        </span>
                                    )}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="From" required error={errors.start_date}>
                        <input
                            type="date"
                            value={data.start_date}
                            onChange={(e) => {
                                const start = e.target.value;
                                setData('start_date', start);

                                if (data.end_date < start) {
                                    setData('end_date', start);
                                }
                            }}
                            className={inputClass}
                            required
                        />
                    </Field>
                    <Field label="To" required error={errors.end_date}>
                        <input
                            type="date"
                            value={data.end_date}
                            min={data.start_date}
                            onChange={(e) => setData('end_date', e.target.value)}
                            className={inputClass}
                            required
                        />
                    </Field>
                </div>

                {canHalfDay && (
                    <div className="flex items-center justify-between rounded-lg border border-sidebar-border/70 px-3 py-2.5 dark:border-sidebar-border">
                        <div>
                            <Label className="text-sm">Half day</Label>
                            <p className="text-xs text-muted-foreground">
                                Charge only half a day.
                            </p>
                        </div>
                        <Switch
                            checked={data.is_half_day}
                            onCheckedChange={(v) => setData('is_half_day', v)}
                        />
                    </div>
                )}

                {isHalfDay && (
                    <Field label="Period" error={errors.half_day_period}>
                        <Select
                            value={data.half_day_period}
                            onValueChange={(v) =>
                                setData('half_day_period', v as 'morning' | 'afternoon')
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="morning">Morning</SelectItem>
                                <SelectItem value="afternoon">
                                    Afternoon
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                )}

                <div className="rounded-lg bg-muted/50 px-3 py-2 text-sm">
                    <span className="text-muted-foreground">
                        Charges approximately{' '}
                    </span>
                    <span className="font-semibold tabular-nums">
                        {estimate} working day{estimate === 1 ? '' : 's'}
                    </span>
                    <span className="text-muted-foreground">
                        {' '}
                        (weekends excluded).
                    </span>
                </div>

                <Field label="Reason" error={errors.reason}>
                    <textarea
                        value={data.reason ?? ''}
                        onChange={(e) => setData('reason', e.target.value)}
                        rows={3}
                        placeholder="Optional note for the approver…"
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                </Field>
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
                    <Button
                        type="submit"
                        disabled={
                            processing ||
                            !data.employee_id ||
                            !data.leave_type_id
                        }
                    >
                        {processing && <Spinner />}
                        {isEditing ? 'Save changes' : 'File leave'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}

/** Mirror of the server's working-day count, for a live estimate. */
function workingDays(start: string, end: string): number {
    if (!start || !end || end < start) {
        return 0;
    }

    const from = new Date(start);
    const to = new Date(end);
    let days = 0;

    for (let d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
        const day = d.getDay();

        if (day !== 0 && day !== 6) {
            days++;
        }
    }

    return days;
}

function Field({
    label,
    required = false,
    error,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <Label className="mb-1.5 block">
                {label}
                {required && <span className="ml-0.5 text-destructive">*</span>}
            </Label>
            {children}
            <InputError message={error} className="mt-1.5" />
        </div>
    );
}
