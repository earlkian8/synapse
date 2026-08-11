import { useForm } from '@inertiajs/react';
import { Target } from 'lucide-react';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { kpiConfigRoutes } from '../routes';
import type { KpiCriterion, RatingScaleOption } from '../types';

type Props = {
    criterion: KpiCriterion | null;
    scales: RatingScaleOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * A catalogue criterion: what gets measured, on which scale, and the weight a
 * framework starts it at. The weight is a starting point rather than a rule —
 * each framework sets its own, because the same criterion can matter more in one
 * population than another.
 */
export function CriterionModal({
    criterion,
    scales,
    open,
    onOpenChange,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Target />
                        </ModalIcon>
                    }
                    title={criterion ? 'Edit criterion' : 'New criterion'}
                    description="A dimension performance is measured on. Frameworks draw from this catalogue."
                />
                {open && (
                    <FormBody
                        key={criterion?.id ?? 'new'}
                        criterion={criterion}
                        scales={scales}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    criterion,
    scales,
    onDone,
}: {
    criterion: KpiCriterion | null;
    scales: RatingScaleOption[];
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        name: criterion?.name ?? '',
        description: criterion?.description ?? '',
        weight: criterion?.weight ?? 20,
        rating_scale_id: String(
            criterion?.rating_scale_id ??
                scales.find((scale) => scale.is_default)?.id ??
                scales[0]?.id ??
                '',
        ),
        is_active: criterion?.is_active ?? true,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onDone };

        if (criterion) {
            post(kpiConfigRoutes.criteria.update(criterion.hashid), opts);
        } else {
            post(kpiConfigRoutes.criteria.store, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <FormField label="Name" required error={errors.name}>
                    <Input
                        value={data.name}
                        onChange={(event) =>
                            setData('name', event.target.value)
                        }
                        placeholder="e.g. Quality of work"
                        required
                    />
                </FormField>

                <FormField
                    label="What it means"
                    hint="Shown to the evaluator under the criterion on the scorecard."
                    error={errors.description}
                >
                    <Input
                        value={data.description ?? ''}
                        onChange={(event) =>
                            setData('description', event.target.value)
                        }
                        placeholder="Accuracy, thoroughness and overall standard of output."
                    />
                </FormField>

                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Rated on"
                        required
                        error={errors.rating_scale_id}
                    >
                        <FormSelect
                            value={data.rating_scale_id}
                            onChange={(value) =>
                                setData('rating_scale_id', value)
                            }
                            options={scales.map((scale) => ({
                                value: String(scale.id),
                                label: `${scale.name} · ${scale.descriptor}`,
                            }))}
                        />
                    </FormField>

                    <FormField
                        label="Default weight"
                        required
                        hint="A framework can override it."
                        error={errors.weight}
                    >
                        <Input
                            type="number"
                            min="0"
                            max="100"
                            value={data.weight}
                            onChange={(event) =>
                                setData('weight', Number(event.target.value))
                            }
                            className="tabular-nums"
                        />
                    </FormField>
                </div>

                <div className="flex items-center gap-2.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                    <Checkbox
                        id="criterion-active"
                        checked={data.is_active}
                        onCheckedChange={(checked) =>
                            setData('is_active', checked === true)
                        }
                    />
                    <Label
                        htmlFor="criterion-active"
                        className="cursor-pointer text-sm font-normal"
                    >
                        Offer this criterion when building a framework
                    </Label>
                </div>
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
                    {criterion ? 'Save changes' : 'Create criterion'}
                </Button>
            </ModalFooter>
        </form>
    );
}
