import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { TYPE_OPTIONS } from '../constants';
import { offboardingRoutes } from '../routes';
import type { EmployeeOption, OffboardingType, ProgramOption } from '../types';

type Props = {
    employees: EmployeeOption[];
    programs: ProgramOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/** Sentinel: let the provisioner pick the best-matching active template. */
const AUTO = '__auto__';

export function InitiateOffboardingSheet({
    employees,
    programs,
    open,
    onOpenChange,
}: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>Start offboarding</SheetTitle>
                    <SheetDescription>
                        Open an exit case and generate the standard clearance
                        checklist.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        employees={employees}
                        programs={programs}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    employees,
    programs,
    onDone,
}: {
    employees: EmployeeOption[];
    programs: ProgramOption[];
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        employee_id: '',
        type: 'resignation' as OffboardingType,
        offboarding_program_id: AUTO,
        notice_date: '',
        last_working_day: '',
        reason: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            employee_id: payload.employee_id
                ? Number(payload.employee_id)
                : null,
            offboarding_program_id:
                payload.offboarding_program_id === AUTO
                    ? null
                    : Number(payload.offboarding_program_id),
            notice_date: payload.notice_date || null,
            last_working_day: payload.last_working_day || null,
            reason: payload.reason || null,
        }));

        post(offboardingRoutes.store, { onSuccess: () => onDone() });
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div>
                    <Label className="mb-1.5 block">
                        Employee
                        <span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Select
                        value={data.employee_id}
                        onValueChange={(v) => setData('employee_id', v)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Select an employee…" />
                        </SelectTrigger>
                        <SelectContent>
                            {employees.length === 0 && (
                                <div className="px-2 py-1.5 text-sm text-muted-foreground">
                                    No active employees to offboard.
                                </div>
                            )}
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
                    <InputError
                        message={errors.employee_id}
                        className="mt-1.5"
                    />
                </div>

                <div>
                    <Label className="mb-1.5 block">
                        Exit type
                        <span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Select
                        value={data.type}
                        onValueChange={(v) =>
                            setData('type', v as OffboardingType)
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {TYPE_OPTIONS.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.type} className="mt-1.5" />
                </div>

                {programs.length > 0 && (
                    <div>
                        <Label className="mb-1.5 block">
                            Clearance template
                        </Label>
                        <Select
                            value={data.offboarding_program_id}
                            onValueChange={(v) =>
                                setData('offboarding_program_id', v)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={AUTO}>
                                    Automatic
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · best match for the employee
                                    </span>
                                </SelectItem>
                                {programs.map((p) => (
                                    <SelectItem key={p.id} value={String(p.id)}>
                                        {p.name}
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {p.items_count} item
                                            {p.items_count === 1 ? '' : 's'}
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError
                            message={errors.offboarding_program_id}
                            className="mt-1.5"
                        />
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label className="mb-1.5 block">Notice date</Label>
                        <Input
                            type="date"
                            value={data.notice_date}
                            onChange={(e) =>
                                setData('notice_date', e.target.value)
                            }
                        />
                        <InputError
                            message={errors.notice_date}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label className="mb-1.5 block">Last working day</Label>
                        <Input
                            type="date"
                            value={data.last_working_day}
                            onChange={(e) =>
                                setData('last_working_day', e.target.value)
                            }
                        />
                        <InputError
                            message={errors.last_working_day}
                            className="mt-1.5"
                        />
                    </div>
                </div>

                <div>
                    <Label className="mb-1.5 block">Reason</Label>
                    <textarea
                        value={data.reason}
                        onChange={(e) => setData('reason', e.target.value)}
                        rows={3}
                        placeholder="Context for the exit (kept internal)…"
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                    <InputError message={errors.reason} className="mt-1.5" />
                </div>

                <p className="text-xs text-muted-foreground">
                    The clearance checklist is generated automatically from the
                    selected template — you can tailor it afterwards.
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
                    <Button
                        type="submit"
                        disabled={processing || !data.employee_id}
                    >
                        {processing && <Spinner />}
                        Start offboarding
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}
