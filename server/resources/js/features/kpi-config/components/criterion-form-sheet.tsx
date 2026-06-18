import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import type { KpiCriterion } from '../types';

type Props = {
    criterion: KpiCriterion | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

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
                        against.
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
