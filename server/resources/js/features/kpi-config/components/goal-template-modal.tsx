import { useForm } from '@inertiajs/react';
import { Flag } from 'lucide-react';
import { FormField } from '@/components/form-field';
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
import { TEXTAREA } from '@/features/performance/constants';
import type {
    GoalLibraryEntry,
    GoalMeasure,
} from '@/features/performance/types';
import { cn } from '@/lib/utils';
import { kpiConfigRoutes } from '../routes';

type Props = {
    template: GoalLibraryEntry | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * A goal-library entry (ADR 0073): wording and a target a goal can start from —
 * progress to 100 %, or a number from a start to a target in a unit. Setting a
 * goal from it copies these, so editing the entry never moves a goal in flight.
 */
export function GoalTemplateModal({ template, open, onOpenChange }: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Flag />
                        </ModalIcon>
                    }
                    title={template ? 'Edit library goal' : 'New library goal'}
                    description="Goals set from it copy its wording and target. Changing it later never moves a goal already set."
                />
                {open && (
                    <FormBody
                        key={template?.id ?? 'new'}
                        template={template}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    template,
    onDone,
}: {
    template: GoalLibraryEntry | null;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        name: template?.name ?? '',
        description: template?.description ?? '',
        measure: (template?.measure ?? 'percent') as GoalMeasure,
        start_value: String(template?.start_value ?? 0),
        target_value:
            template && template.measure === 'number'
                ? String(template.target_value)
                : '',
        unit: template?.unit ?? '',
        is_active: template?.is_active ?? true,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onDone };

        if (template) {
            post(kpiConfigRoutes.goals.update(template.hashid), opts);
        } else {
            post(kpiConfigRoutes.goals.store, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <FormField label="Goal" required error={errors.name}>
                    <Input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="e.g. Reduce ticket backlog"
                        required
                    />
                </FormField>

                <FormField
                    label="What success looks like"
                    error={errors.description}
                >
                    <textarea
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={3}
                        maxLength={2000}
                        className={TEXTAREA}
                    />
                </FormField>

                <FormField label="Measured as" group error={errors.measure}>
                    <div className="flex flex-wrap gap-2">
                        {(
                            [
                                ['percent', 'Progress to 100%'],
                                ['number', 'A number to reach'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => setData('measure', value)}
                                aria-pressed={data.measure === value}
                                className={cn(
                                    'min-h-9 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                                    data.measure === value
                                        ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 text-foreground'
                                        : 'border-border text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </FormField>

                {data.measure === 'number' && (
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <FormField
                            label="Starts at"
                            required
                            error={errors.start_value}
                        >
                            <Input
                                type="number"
                                inputMode="decimal"
                                value={data.start_value}
                                onChange={(e) =>
                                    setData('start_value', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            label="Target"
                            required
                            error={errors.target_value}
                        >
                            <Input
                                type="number"
                                inputMode="decimal"
                                value={data.target_value}
                                onChange={(e) =>
                                    setData('target_value', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            label="Unit"
                            error={errors.unit}
                            className="col-span-2 sm:col-span-1"
                        >
                            <Input
                                value={data.unit}
                                onChange={(e) =>
                                    setData('unit', e.target.value)
                                }
                                placeholder="tickets"
                                maxLength={40}
                            />
                        </FormField>
                    </div>
                )}
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
                    {template ? 'Save changes' : 'Add to library'}
                </Button>
            </ModalFooter>
        </form>
    );
}
