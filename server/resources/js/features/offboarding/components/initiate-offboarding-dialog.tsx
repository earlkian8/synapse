import { useForm } from '@inertiajs/react';
import { UserRoundMinus } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
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

export function InitiateOffboardingDialog({
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
                            <UserRoundMinus />
                        </ModalIcon>
                    }
                    title="Start offboarding"
                    description="Open an exit case and generate the standard clearance checklist."
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
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                {employees.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                        No active employees to offboard — everyone is already
                        leaving or has left.
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

                <FormField label="Exit type" required error={errors.type}>
                    <FormSelect
                        value={data.type}
                        onChange={(v) => setData('type', v as OffboardingType)}
                        options={TYPE_OPTIONS}
                    />
                </FormField>

                {programs.length > 0 && (
                    <FormField
                        label="Clearance template"
                        error={errors.offboarding_program_id}
                        hint="The checklist is generated from this template — you can tailor it afterwards."
                    >
                        <FormSelect
                            value={data.offboarding_program_id}
                            onChange={(v) =>
                                setData('offboarding_program_id', v)
                            }
                            options={[
                                {
                                    value: AUTO,
                                    label: 'Automatic — best match for the employee',
                                },
                                ...programs.map((p) => ({
                                    value: String(p.id),
                                    label: `${p.name} · ${p.items_count} item${p.items_count === 1 ? '' : 's'}`,
                                })),
                            ]}
                        />
                    </FormField>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField label="Notice date" error={errors.notice_date}>
                        <Input
                            type="date"
                            value={data.notice_date}
                            onChange={(e) =>
                                setData('notice_date', e.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        label="Last working day"
                        error={errors.last_working_day}
                    >
                        <Input
                            type="date"
                            value={data.last_working_day}
                            onChange={(e) =>
                                setData('last_working_day', e.target.value)
                            }
                        />
                    </FormField>
                </div>

                <FormField
                    label="Reason"
                    error={errors.reason}
                    hint="Kept internal to the people who manage offboarding."
                >
                    <textarea
                        value={data.reason}
                        onChange={(e) => setData('reason', e.target.value)}
                        rows={3}
                        placeholder="Context for the exit…"
                        className="flex w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 aria-invalid:border-destructive aria-invalid:ring-destructive/20"
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
                    Start offboarding
                </Button>
            </ModalFooter>
        </form>
    );
}
