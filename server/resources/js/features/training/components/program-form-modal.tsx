import { useForm } from '@inertiajs/react';
import { GraduationCap } from 'lucide-react';
import { FormField } from '@/components/form-field';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
    ModalSection,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import {
    ENROLLMENT_MODE_HINTS,
    ENROLLMENT_MODE_LABELS,
    TEXTAREA,
} from '../constants';
import { trainingRoutes } from '../routes';
import type { EnrollmentMode, TrainingProgram } from '../types';

type Props = {
    program: TrainingProgram | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const MODES: EnrollmentMode[] = ['closed', 'open', 'approval'];

/**
 * Create or edit a training program: what it is and when it runs, who may join
 * it themselves (ADR 0074), whether completing it earns a certificate and for
 * how long, and what it may spend (ADR 0075).
 */
export function ProgramFormModal({ program, open, onOpenChange }: Props) {
    const isEditing = Boolean(program);

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <GraduationCap />
                        </ModalIcon>
                    }
                    title={
                        isEditing
                            ? 'Edit training program'
                            : 'New training program'
                    }
                    description="A program or cohort: its provider and dates, who may join it, the certificate it earns and its budget."
                />

                {open && (
                    <FormBody
                        key={program?.id ?? 'new'}
                        program={program}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    program,
    onDone,
}: {
    program: TrainingProgram | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(program);
    const { data, setData, post, processing, errors } = useForm({
        name: program?.name ?? '',
        provider: program?.provider ?? '',
        description: program?.description ?? '',
        start_date: program?.start_date ?? '',
        end_date: program?.end_date ?? '',
        capacity: program?.capacity ?? ('' as number | ''),
        enrollment_mode:
            program?.enrollment_mode ?? ('closed' as EnrollmentMode),
        issues_certificate: program?.issues_certificate ?? false,
        certificate_validity_months:
            program?.certificate_validity_months ?? ('' as number | ''),
        budget: program?.budget ?? ('' as number | ''),
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && program) {
            post(trainingRoutes.update(program.hashid), opts);
        } else {
            post(trainingRoutes.store, opts);
        }
    };

    const number = (value: string): number | '' =>
        value === '' ? '' : Number(value);

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-6">
                <ModalSection title="The program">
                    <FormField label="Name" required error={errors.name}>
                        <Input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Leadership Essentials"
                            maxLength={160}
                            required
                        />
                    </FormField>

                    <FormField label="Provider" error={errors.provider}>
                        <Input
                            value={data.provider}
                            onChange={(e) =>
                                setData('provider', e.target.value)
                            }
                            placeholder="e.g. Dale Carnegie, In-house"
                            maxLength={160}
                        />
                    </FormField>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField label="Start date" error={errors.start_date}>
                            <Input
                                type="date"
                                value={data.start_date ?? ''}
                                onChange={(e) =>
                                    setData('start_date', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField label="End date" error={errors.end_date}>
                            <Input
                                type="date"
                                value={data.end_date ?? ''}
                                onChange={(e) =>
                                    setData('end_date', e.target.value)
                                }
                            />
                        </FormField>
                    </div>

                    <FormField
                        label="Capacity"
                        hint="Leave blank for no limit."
                        error={errors.capacity}
                    >
                        <Input
                            type="number"
                            min="1"
                            inputMode="numeric"
                            value={data.capacity}
                            onChange={(e) =>
                                setData('capacity', number(e.target.value))
                            }
                            placeholder="Unlimited"
                            className="sm:w-48"
                        />
                    </FormField>

                    <FormField label="Description" error={errors.description}>
                        <textarea
                            value={data.description}
                            onChange={(e) =>
                                setData('description', e.target.value)
                            }
                            rows={3}
                            maxLength={2000}
                            placeholder="What the program covers (optional)"
                            className={TEXTAREA}
                        />
                    </FormField>
                </ModalSection>

                <ModalSection
                    title="Who can join"
                    hint="Employees see programs they can join under Training → Browse."
                >
                    <FormField
                        label="Joining"
                        group
                        error={errors.enrollment_mode}
                        className="[&>label]:sr-only"
                    >
                        <div className="grid gap-2 sm:grid-cols-3">
                            {MODES.map((mode) => (
                                <button
                                    key={mode}
                                    type="button"
                                    onClick={() =>
                                        setData('enrollment_mode', mode)
                                    }
                                    aria-pressed={data.enrollment_mode === mode}
                                    className={cn(
                                        'flex flex-col gap-0.5 rounded-lg border px-3 py-2.5 text-left transition-colors',
                                        data.enrollment_mode === mode
                                            ? 'border-[#0ABFBF] bg-[#0ABFBF]/10'
                                            : 'border-border hover:border-[#0ABFBF]/40',
                                    )}
                                >
                                    <span className="text-sm font-medium">
                                        {ENROLLMENT_MODE_LABELS[mode]}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {ENROLLMENT_MODE_HINTS[mode]}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </FormField>
                </ModalSection>

                <ModalSection title="Certificate">
                    <label className="flex items-start justify-between gap-4">
                        <span className="min-w-0">
                            <span className="block text-sm font-medium">
                                Completing it earns a certificate
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Issued when someone is marked completed, and
                                added to their profile’s certifications.
                                {isEditing &&
                                    ' Turning it on issues one to everyone who already completed.'}
                            </span>
                        </span>
                        <Switch
                            checked={data.issues_certificate}
                            onCheckedChange={(checked) =>
                                setData('issues_certificate', checked)
                            }
                            aria-label="Completing it earns a certificate"
                        />
                    </label>

                    {data.issues_certificate && (
                        <FormField
                            label="Valid for (months)"
                            hint="Leave blank if it never expires. Holders are told 60 days before it does."
                            error={errors.certificate_validity_months}
                        >
                            <Input
                                type="number"
                                min="1"
                                max="240"
                                inputMode="numeric"
                                value={data.certificate_validity_months}
                                onChange={(e) =>
                                    setData(
                                        'certificate_validity_months',
                                        number(e.target.value),
                                    )
                                }
                                placeholder="Never expires"
                                className="sm:w-48"
                            />
                        </FormField>
                    )}
                </ModalSection>

                <ModalSection title="Budget">
                    <FormField
                        label="Program budget (₱)"
                        hint="What it may spend. Record costs on the program’s Costs tab."
                        error={errors.budget}
                    >
                        <Input
                            type="number"
                            min="0"
                            step="0.01"
                            inputMode="decimal"
                            value={data.budget}
                            onChange={(e) =>
                                setData('budget', number(e.target.value))
                            }
                            placeholder="No budget set"
                            className="sm:w-48"
                        />
                    </FormField>
                </ModalSection>
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
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    {isEditing ? 'Save changes' : 'Create program'}
                </Button>
            </ModalFooter>
        </form>
    );
}
