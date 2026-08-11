import { useForm } from '@inertiajs/react';
import { Plus, Ruler, X } from 'lucide-react';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { ScaleLevel, ScaleType } from '@/features/performance/types';
import { kpiConfigRoutes } from '../routes';
import type { RatingScaleOption } from '../types';

const TYPES: { value: ScaleType; label: string; hint: string }[] = [
    {
        value: 'numeric',
        label: 'Numeric range',
        hint: 'A range with a step — 1–5, 1–4, 1–10, or halves.',
    },
    {
        value: 'percentage',
        label: 'Percentage',
        hint: '0–100, for goal attainment and quotas.',
    },
    {
        value: 'levels',
        label: 'Named levels',
        hint: 'Ordered levels an evaluator picks by name, each with a definition.',
    },
];

type Props = {
    scale: RatingScaleOption | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Define one measurement instrument. The three kinds are genuinely different
 * things — a range, a quantity and a set of named levels — so the form changes
 * shape rather than showing every field greyed out.
 */
export function RatingScaleModal({ scale, open, onOpenChange }: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Ruler />
                        </ModalIcon>
                    }
                    title={scale ? 'Edit rating scale' : 'New rating scale'}
                    description="How evaluators rate a criterion. Defined once here, reused by every framework that measures on it."
                />
                {open && (
                    <FormBody
                        key={scale?.id ?? 'new'}
                        scale={scale}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    scale,
    onDone,
}: {
    scale: RatingScaleOption | null;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        name: scale?.name ?? '',
        description: scale?.description ?? '',
        type: (scale?.type ?? 'numeric') as ScaleType,
        min: scale?.min ?? 1,
        max: scale?.max ?? 5,
        step: scale?.step ?? 1,
        levels: (scale?.levels ?? [
            { value: 1, label: '', description: '' },
            { value: 2, label: '', description: '' },
        ]) as ScaleLevel[],
        is_default: scale?.is_default ?? false,
    });

    const setLevel = (index: number, patch: Partial<ScaleLevel>) =>
        setData(
            'levels',
            data.levels.map((level, i) =>
                i === index ? { ...level, ...patch } : level,
            ),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onDone };

        if (scale) {
            post(kpiConfigRoutes.scales.update(scale.hashid), opts);
        } else {
            post(kpiConfigRoutes.scales.store, opts);
        }
    };

    const selected = TYPES.find((type) => type.value === data.type);

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <FormField label="Name" required error={errors.name}>
                    <Input
                        value={data.name}
                        onChange={(event) =>
                            setData('name', event.target.value)
                        }
                        placeholder="e.g. Competency level"
                        required
                    />
                </FormField>

                <FormField
                    label="Description"
                    hint="What this scale is for. Shown when a framework picks it."
                    error={errors.description}
                >
                    <Input
                        value={data.description ?? ''}
                        onChange={(event) =>
                            setData('description', event.target.value)
                        }
                        placeholder="Where this scale belongs"
                    />
                </FormField>

                <FormField
                    label="Kind of scale"
                    group
                    hint={selected?.hint}
                    error={errors.type}
                >
                    <div className="flex flex-wrap gap-2">
                        {TYPES.map((type) => (
                            <button
                                key={type.value}
                                type="button"
                                onClick={() => setData('type', type.value)}
                                aria-pressed={data.type === type.value}
                                className={
                                    data.type === type.value
                                        ? 'min-h-9 rounded-lg border border-[#0ABFBF] bg-[#0ABFBF]/10 px-3 py-1.5 text-sm font-medium'
                                        : 'min-h-9 rounded-lg border border-border px-3 py-1.5 text-sm font-medium text-muted-foreground hover:text-foreground'
                                }
                            >
                                {type.label}
                            </button>
                        ))}
                    </div>
                </FormField>

                {data.type === 'numeric' && (
                    <div className="grid gap-4 sm:grid-cols-3">
                        <FormField label="Lowest" required error={errors.min}>
                            <Input
                                type="number"
                                value={data.min}
                                onChange={(event) =>
                                    setData('min', Number(event.target.value))
                                }
                            />
                        </FormField>
                        <FormField label="Highest" required error={errors.max}>
                            <Input
                                type="number"
                                value={data.max}
                                onChange={(event) =>
                                    setData('max', Number(event.target.value))
                                }
                            />
                        </FormField>
                        <FormField
                            label="Step"
                            hint="1 for whole points"
                            error={errors.step}
                        >
                            <Input
                                type="number"
                                step="0.25"
                                min="0.01"
                                value={data.step}
                                onChange={(event) =>
                                    setData('step', Number(event.target.value))
                                }
                            />
                        </FormField>
                    </div>
                )}

                {data.type === 'levels' && (
                    <ModalSection
                        title="Levels"
                        hint="In order, lowest first. The definition is what the evaluator rates against."
                        action={
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setData('levels', [
                                        ...data.levels,
                                        {
                                            value: data.levels.length
                                                ? Math.max(
                                                      ...data.levels.map(
                                                          (l) => l.value,
                                                      ),
                                                  ) + 1
                                                : 1,
                                            label: '',
                                            description: '',
                                        },
                                    ])
                                }
                                disabled={data.levels.length >= 12}
                            >
                                <Plus className="size-4" />
                                Add level
                            </Button>
                        }
                    >
                        <ul className="space-y-2">
                            {data.levels.map((level, index) => (
                                <li
                                    key={index}
                                    className="grid grid-cols-[4.5rem_minmax(0,1fr)_auto] gap-2 rounded-lg border border-border p-2"
                                >
                                    <Input
                                        type="number"
                                        value={level.value}
                                        onChange={(event) =>
                                            setLevel(index, {
                                                value: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                        aria-label={`Level ${index + 1} value`}
                                        className="tabular-nums"
                                    />
                                    <div className="space-y-2">
                                        <Input
                                            value={level.label}
                                            onChange={(event) =>
                                                setLevel(index, {
                                                    label: event.target.value,
                                                })
                                            }
                                            placeholder="Label, e.g. Proficient"
                                            aria-label={`Level ${index + 1} label`}
                                        />
                                        <Input
                                            value={level.description ?? ''}
                                            onChange={(event) =>
                                                setLevel(index, {
                                                    description:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder="What this level means (optional)"
                                            aria-label={`Level ${index + 1} definition`}
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-8 text-muted-foreground hover:text-destructive"
                                        aria-label={`Remove level ${index + 1}`}
                                        disabled={data.levels.length <= 2}
                                        onClick={() =>
                                            setData(
                                                'levels',
                                                data.levels.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        {errors.levels && (
                            <p className="text-sm text-destructive">
                                {errors.levels}
                            </p>
                        )}
                    </ModalSection>
                )}

                <div className="flex items-center gap-2.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                    <Checkbox
                        id="scale-default"
                        checked={data.is_default}
                        onCheckedChange={(checked) =>
                            setData('is_default', checked === true)
                        }
                    />
                    <Label
                        htmlFor="scale-default"
                        className="cursor-pointer text-sm font-normal"
                    >
                        Offer this scale first when building a framework
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
                    {scale ? 'Save changes' : 'Create scale'}
                </Button>
            </ModalFooter>
        </form>
    );
}
