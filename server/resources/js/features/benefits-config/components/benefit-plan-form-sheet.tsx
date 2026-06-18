import { useForm } from '@inertiajs/react';
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
import {
    CATEGORY_LABELS,
    CATEGORY_ORDER,
    FREQUENCY_LABELS,
} from '@/features/benefits/constants';
import type {
    BenefitCategory,
    BenefitFrequency,
    BenefitPlan,
} from '@/features/benefits/types';
import { benefitsConfigRoutes } from '../routes';

const FREQUENCIES: BenefitFrequency[] = [
    'monthly',
    'quarterly',
    'annual',
    'one_time',
];

type Props = {
    plan: BenefitPlan | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function BenefitPlanFormSheet({ plan, open, onOpenChange }: Props) {
    const isEditing = Boolean(plan);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit benefit plan' : 'New benefit plan'}
                    </SheetTitle>
                    <SheetDescription>
                        A benefit the organisation offers, with its provider and
                        per-period cost.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={plan?.id ?? 'new'}
                        plan={plan}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    plan,
    onDone,
}: {
    plan: BenefitPlan | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(plan);
    const { data, setData, post, processing, errors } = useForm({
        name: plan?.name ?? '',
        category: (plan?.category ?? 'hmo') as BenefitCategory,
        provider: plan?.provider ?? '',
        description: plan?.description ?? '',
        employee_cost: plan?.employee_cost ?? 0,
        employer_cost: plan?.employer_cost ?? 0,
        frequency: (plan?.frequency ?? 'monthly') as BenefitFrequency,
        is_active: plan?.is_active ?? true,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && plan) {
            post(benefitsConfigRoutes.update(plan.hashid), opts);
        } else {
            post(benefitsConfigRoutes.store, opts);
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
                        placeholder="e.g. Maxicare HMO – Standard"
                        required
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label className="mb-1.5 block">Category</Label>
                        <Select
                            value={data.category}
                            onValueChange={(v) =>
                                setData('category', v as BenefitCategory)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {CATEGORY_ORDER.map((c) => (
                                    <SelectItem key={c} value={c}>
                                        {CATEGORY_LABELS[c]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError
                            message={errors.category}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label className="mb-1.5 block">Frequency</Label>
                        <Select
                            value={data.frequency}
                            onValueChange={(v) =>
                                setData('frequency', v as BenefitFrequency)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {FREQUENCIES.map((f) => (
                                    <SelectItem key={f} value={f}>
                                        {FREQUENCY_LABELS[f]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError
                            message={errors.frequency}
                            className="mt-1.5"
                        />
                    </div>
                </div>

                <div>
                    <Label className="mb-1.5 block">Provider</Label>
                    <Input
                        value={data.provider}
                        onChange={(e) => setData('provider', e.target.value)}
                        placeholder="e.g. Maxicare"
                    />
                    <InputError message={errors.provider} className="mt-1.5" />
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label className="mb-1.5 block">Employee cost</Label>
                        <Input
                            type="number"
                            min="0"
                            step="0.01"
                            inputMode="decimal"
                            value={data.employee_cost}
                            onChange={(e) =>
                                setData('employee_cost', Number(e.target.value))
                            }
                        />
                        <InputError
                            message={errors.employee_cost}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label className="mb-1.5 block">Employer cost</Label>
                        <Input
                            type="number"
                            min="0"
                            step="0.01"
                            inputMode="decimal"
                            value={data.employer_cost}
                            onChange={(e) =>
                                setData('employer_cost', Number(e.target.value))
                            }
                        />
                        <InputError
                            message={errors.employer_cost}
                            className="mt-1.5"
                        />
                    </div>
                </div>

                <div>
                    <Label className="mb-1.5 block">Description</Label>
                    <textarea
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={3}
                        placeholder="Coverage summary (optional)"
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
                            Inactive plans are hidden from new enrollments.
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
