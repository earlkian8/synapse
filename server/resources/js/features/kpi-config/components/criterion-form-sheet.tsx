import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
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
import { Switch } from '@/components/ui/switch';
import { kpiConfigRoutes } from '../routes';
import type { KpiCriterion, ScaleLevel, ScaleType } from '../types';

type Props = {
    criterion: KpiCriterion | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const SCALE_TYPES: { value: ScaleType; label: string; hint: string }[] = [
    {
        value: 'points',
        label: 'Points scale',
        hint: 'Rate from 1 to a top value (e.g. 1–5 or 1–10).',
    },
    {
        value: 'percentage',
        label: 'Percentage',
        hint: 'Score from 0 to 100%.',
    },
    {
        value: 'scale',
        label: 'Descriptive levels',
        hint: 'Named ratings (e.g. letter grades, competency bands, pass/fail).',
    },
];

function defaultLevels(criterion: KpiCriterion | null): ScaleLevel[] {
    if (criterion?.scale_type === 'scale' && criterion.scale_levels?.length) {
        return criterion.scale_levels;
    }

    return [
        { label: 'Does not meet', value: 1 },
        { label: 'Meets', value: 2 },
        { label: 'Exceeds', value: 3 },
    ];
}

export function CriterionFormSheet({ criterion, open, onOpenChange }: Props) {
    const isEditing = Boolean(criterion);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit KPI criterion' : 'New KPI criterion'}
                    </SheetTitle>
                    <SheetDescription>
                        A weighted dimension evaluations score employees
                        against, on the rating scale you choose.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={criterion?.id ?? 'new'}
                        criterion={criterion}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    criterion,
    onDone,
}: {
    criterion: KpiCriterion | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(criterion);
    const { data, setData, post, processing, errors } = useForm({
        name: criterion?.name ?? '',
        description: criterion?.description ?? '',
        weight: criterion?.weight ?? 0,
        scale_type: (criterion?.scale_type ?? 'points') as ScaleType,
        scale_max: criterion?.scale_type === 'points' ? criterion.scale_max : 5,
        scale_levels: defaultLevels(criterion),
        is_active: criterion?.is_active ?? true,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && criterion) {
            post(kpiConfigRoutes.criteria.update(criterion.hashid), opts);
        } else {
            post(kpiConfigRoutes.criteria.store, opts);
        }
    };

    const setLevel = (index: number, patch: Partial<ScaleLevel>) =>
        setData(
            'scale_levels',
            data.scale_levels.map((level, i) =>
                i === index ? { ...level, ...patch } : level,
            ),
        );

    const addLevel = () =>
        setData('scale_levels', [
            ...data.scale_levels,
            {
                label: '',
                value:
                    data.scale_levels.reduce(
                        (max, l) => Math.max(max, l.value),
                        0,
                    ) + 1,
            },
        ]);

    const removeLevel = (index: number) =>
        setData(
            'scale_levels',
            data.scale_levels.filter((_, i) => i !== index),
        );

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div>
                    <Label className="mb-1.5 block">
                        Name<span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="e.g. Quality of Work"
                        required
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <div>
                    <Label className="mb-1.5 block">
                        Weight (%)
                        <span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Input
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        inputMode="decimal"
                        value={data.weight}
                        onChange={(e) =>
                            setData('weight', Number(e.target.value))
                        }
                    />
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        Relative share of the overall score. Weights across
                        criteria typically sum to 100.
                    </p>
                    <InputError message={errors.weight} className="mt-1.5" />
                </div>

                {/* Rating scale */}
                <div className="space-y-3 rounded-lg border border-sidebar-border/70 p-3.5 dark:border-sidebar-border">
                    <div>
                        <Label className="mb-1.5 block">Rating scale</Label>
                        <Select
                            value={data.scale_type}
                            onValueChange={(v) =>
                                setData('scale_type', v as ScaleType)
                            }
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {SCALE_TYPES.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            {
                                SCALE_TYPES.find(
                                    (t) => t.value === data.scale_type,
                                )?.hint
                            }
                        </p>
                    </div>

                    {data.scale_type === 'points' && (
                        <div>
                            <Label className="mb-1.5 block text-xs">
                                Top of scale
                            </Label>
                            <Input
                                type="number"
                                min="2"
                                max="100"
                                step="1"
                                value={data.scale_max}
                                onChange={(e) =>
                                    setData('scale_max', Number(e.target.value))
                                }
                                className="w-28"
                            />
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                Evaluators rate from 1 to{' '}
                                {data.scale_max || '…'}.
                            </p>
                            <InputError
                                message={errors.scale_max}
                                className="mt-1.5"
                            />
                        </div>
                    )}

                    {data.scale_type === 'scale' && (
                        <div className="space-y-2">
                            <Label className="block text-xs">
                                Levels (low to high)
                            </Label>
                            {data.scale_levels.map((level, index) => (
                                <div
                                    key={index}
                                    className="flex items-center gap-2"
                                >
                                    <Input
                                        value={level.label}
                                        onChange={(e) =>
                                            setLevel(index, {
                                                label: e.target.value,
                                            })
                                        }
                                        placeholder="Level name"
                                        className="flex-1"
                                    />
                                    <Input
                                        type="number"
                                        step="1"
                                        value={level.value}
                                        onChange={(e) =>
                                            setLevel(index, {
                                                value: Number(e.target.value),
                                            })
                                        }
                                        className="w-20"
                                        aria-label="Level value"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                        onClick={() => removeLevel(index)}
                                        disabled={data.scale_levels.length <= 2}
                                        aria-label="Remove level"
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addLevel}
                                disabled={data.scale_levels.length >= 12}
                            >
                                <Plus className="size-4" />
                                Add level
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                Each level's value sets its position; the
                                overall score normalises every scale onto a
                                common range.
                            </p>
                            <InputError
                                message={errors.scale_levels}
                                className="mt-1"
                            />
                        </div>
                    )}
                </div>

                <div>
                    <Label className="mb-1.5 block">Description</Label>
                    <textarea
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={3}
                        placeholder="What this criterion measures (optional)"
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                    />
                    <InputError
                        message={errors.description}
                        className="mt-1.5"
                    />
                </div>

                <div className="flex items-center justify-between rounded-lg border border-sidebar-border/70 px-3 py-2.5 dark:border-sidebar-border">
                    <div className="pr-3">
                        <Label className="text-sm">Active</Label>
                        <p className="text-xs text-muted-foreground">
                            Inactive criteria are excluded from new evaluations.
                        </p>
                    </div>
                    <Switch
                        checked={data.is_active}
                        onCheckedChange={(v) => setData('is_active', v)}
                    />
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
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        {isEditing ? 'Save changes' : 'Create'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}
