import { useForm } from '@inertiajs/react';
import { FileWarning } from 'lucide-react';
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
import { TEXTAREA } from '@/features/training/constants';
import { disciplineRoutes } from '../routes';
import type { ReportOptions } from '../types';

const NO_OFFENSE = 'none';

/**
 * Report an incident (ADR 0105): who, when and where, what happened, and who
 * saw it. Nothing is served on the employee yet — HR reads it first and decides
 * whether a notice to explain should follow.
 */
export function ReportIncidentDialog({
    open,
    onOpenChange,
    options,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    options: ReportOptions;
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <FileWarning />
                        </ModalIcon>
                    }
                    title="Report an incident"
                    description="HR reads it before anything is sent to the employee. Write what happened as you saw it, with dates, times and places."
                />
                {open && (
                    <Body
                        options={options}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function Body({
    options,
    onDone,
}: {
    options: ReportOptions;
    onDone: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        employee_id: '',
        conduct_offense_id: NO_OFFENSE,
        incident_date: options.today,
        incident_time: '',
        location: '',
        narrative: '',
        witnesses: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        transform((payload) => ({
            ...payload,
            employee_id:
                payload.employee_id === '' ? null : Number(payload.employee_id),
            conduct_offense_id:
                payload.conduct_offense_id === NO_OFFENSE
                    ? null
                    : Number(payload.conduct_offense_id),
            incident_time: payload.incident_time || null,
        }));
        post(disciplineRoutes.store, { onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Employee"
                        required
                        error={errors.employee_id}
                    >
                        <FormSelect
                            value={data.employee_id}
                            onChange={(value) => setData('employee_id', value)}
                            placeholder="Choose who it’s about"
                            options={options.employees.map((employee) => ({
                                value: String(employee.id),
                                label: employee.employee_no
                                    ? `${employee.name} (${employee.employee_no})`
                                    : employee.name,
                            }))}
                        />
                    </FormField>
                    <FormField
                        label="Offense"
                        error={errors.conduct_offense_id}
                        hint="If you know which. HR can set it later."
                    >
                        <FormSelect
                            value={data.conduct_offense_id}
                            onChange={(value) =>
                                setData('conduct_offense_id', value)
                            }
                            options={[
                                { value: NO_OFFENSE, label: 'Not sure' },
                                ...options.offenses.map((offense) => ({
                                    value: String(offense.id),
                                    label: `${offense.code} ${offense.title}`,
                                })),
                            ]}
                        />
                    </FormField>
                </div>

                <div className="grid gap-5 sm:grid-cols-[1fr_9rem_1.4fr]">
                    <FormField
                        label="Date"
                        required
                        error={errors.incident_date}
                    >
                        <Input
                            type="date"
                            value={data.incident_date}
                            max={options.today}
                            onChange={(event) =>
                                setData('incident_date', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField label="Time" error={errors.incident_time}>
                        <Input
                            type="time"
                            value={data.incident_time}
                            onChange={(event) =>
                                setData('incident_time', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField label="Where" error={errors.location}>
                        <Input
                            value={data.location}
                            onChange={(event) =>
                                setData('location', event.target.value)
                            }
                            maxLength={160}
                            placeholder="Warehouse loading bay"
                        />
                    </FormField>
                </div>

                <FormField
                    label="What happened"
                    required
                    error={errors.narrative}
                    hint="The facts, in order. A notice to explain has to narrate them in detail."
                >
                    <textarea
                        value={data.narrative}
                        onChange={(event) =>
                            setData('narrative', event.target.value)
                        }
                        rows={6}
                        maxLength={5000}
                        className={TEXTAREA}
                    />
                </FormField>

                <FormField
                    label="Who saw it"
                    error={errors.witnesses}
                    hint="Names only HR sees. They aren’t put on the notice."
                >
                    <Input
                        value={data.witnesses}
                        onChange={(event) =>
                            setData('witnesses', event.target.value)
                        }
                        maxLength={1000}
                    />
                </FormField>
            </ModalBody>

            <ModalFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    Send to HR
                </Button>
            </ModalFooter>
        </form>
    );
}
