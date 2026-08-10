import { useForm } from '@inertiajs/react';
import { Rocket } from 'lucide-react';
import { useMemo } from 'react';
import { FormField } from '@/components/form-field';
import { FormSelect } from '@/components/form-select';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
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

export function StartOnboardingDialog({
    employees,
    programs,
    open,
    onOpenChange,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Rocket />
                        </ModalIcon>
                    }
                    title="Start onboarding"
                    description="Pick an employee and a program to generate their checklist."
                />

                {open && (
                    <FormBody
                        employees={employees}
                        programs={programs}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
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

    const chosenProgram = useMemo(
        () => programs.find((p) => String(p.id) === data.program_id) ?? null,
        [programs, data.program_id],
    );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            employee_id: payload.employee_id
                ? Number(payload.employee_id)
                : null,
            program_id:
                payload.program_id === AUTO ? null : Number(payload.program_id),
        }));

        post(onboardingRoutes.store, { onSuccess: () => onDone() });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                {employees.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                        Everyone on the roster is already onboarding. Hire
                        somebody, or reopen a completed case instead.
                    </p>
                ) : (
                    <FormField
                        label="Employee"
                        required
                        error={errors.employee_id}
                    >
                        <FormSelect
                            value={data.employee_id}
                            onChange={(v) => setData('employee_id', v)}
                            placeholder="Select an employee…"
                            options={employees.map((e) => ({
                                value: String(e.id),
                                label: `${e.full_name} · ${e.employee_no}`,
                            }))}
                        />
                    </FormField>
                )}

                <FormField
                    label="Program"
                    error={errors.program_id}
                    hint={
                        chosenProgram
                            ? `${chosenProgram.tasks_count} task${chosenProgram.tasks_count === 1 ? '' : 's'} copied onto the checklist, due from the hire date.`
                            : 'The best-matching program for this hire is chosen automatically, and its tasks are due from the hire date.'
                    }
                >
                    <FormSelect
                        value={data.program_id}
                        onChange={(v) => setData('program_id', v)}
                        options={[
                            { value: AUTO, label: 'Auto — best match' },
                            ...programs.map((p) => ({
                                value: String(p.id),
                                label: `${p.name} · ${p.tasks_count} tasks`,
                            })),
                        ]}
                    />
                </FormField>
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
                    disabled={processing || !data.employee_id}
                >
                    {processing && <Spinner />}
                    Start onboarding
                </Button>
            </ModalFooter>
        </form>
    );
}
