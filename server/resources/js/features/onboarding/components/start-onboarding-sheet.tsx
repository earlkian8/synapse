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
import { onboardingRoutes } from '../routes';
import type { EmployeeOption, ProgramOption } from '../types';

type Props = {
    employees: EmployeeOption[];
    programs: ProgramOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const AUTO = '__auto__';

export function StartOnboardingSheet({
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
                    <SheetTitle>Start onboarding</SheetTitle>
                    <SheetDescription>
                        Pick an employee and a program to generate their
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
    const defaultProgram = useMemo(
        () => programs.find((p) => p.is_default),
        [programs],
    );

    const { data, setData, post, processing, errors, transform } = useForm({
        employee_id: '',
        program_id: defaultProgram ? String(defaultProgram.id) : AUTO,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            employee_id: payload.employee_id ? Number(payload.employee_id) : null,
            program_id:
                payload.program_id === AUTO
                    ? null
                    : Number(payload.program_id),
        }));

        post(onboardingRoutes.store, { onSuccess: () => onDone() });
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
                                    Everyone is already onboarding.
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
                    <InputError message={errors.employee_id} className="mt-1.5" />
                </div>

                <div>
                    <Label className="mb-1.5 block">Program</Label>
                    <Select
                        value={data.program_id}
                        onValueChange={(v) => setData('program_id', v)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={AUTO}>
                                Auto — best match
                            </SelectItem>
                            {programs.map((p) => (
                                <SelectItem key={p.id} value={String(p.id)}>
                                    {p.name}
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {p.tasks_count} tasks
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        The program's tasks are copied onto the checklist with
                        due dates from the hire date.
                    </p>
                    <InputError message={errors.program_id} className="mt-1.5" />
                </div>
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
                        Start onboarding
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}
