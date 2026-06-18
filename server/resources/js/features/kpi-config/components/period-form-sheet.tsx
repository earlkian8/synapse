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
import { PERIOD_STATUS_LABELS } from '@/features/performance/constants';
import type {
    EvaluationPeriodOption,
    PeriodStatus,
} from '@/features/performance/types';
import { kpiConfigRoutes } from '../routes';

const STATUSES: PeriodStatus[] = ['draft', 'open', 'closed'];

type Props = {
    period: EvaluationPeriodOption | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function PeriodFormSheet({ period, open, onOpenChange }: Props) {
    const isEditing = Boolean(period);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing
                            ? 'Edit evaluation period'
                            : 'New evaluation period'}
                    </SheetTitle>
                    <SheetDescription>
                        A review cycle evaluations are conducted within.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={period?.id ?? 'new'}
                        period={period}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    period,
    onDone,
}: {
    period: EvaluationPeriodOption | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(period);
    const { data, setData, post, processing, errors } = useForm({
        name: period?.name ?? '',
        start_date: period?.start_date ?? '',
        end_date: period?.end_date ?? '',
        status: (period?.status ?? 'draft') as PeriodStatus,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && period) {
            post(kpiConfigRoutes.periods.update(period.hashid), opts);
        } else {
            post(kpiConfigRoutes.periods.store, opts);
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
                        placeholder="e.g. H1 2026 Review"
                        required
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label className="mb-1.5 block">
                            Start date
                            <span className="ml-0.5 text-destructive">*</span>
                        </Label>
                        <Input
                            type="date"
                            value={data.start_date ?? ''}
                            onChange={(e) =>
                                setData('start_date', e.target.value)
                            }
                            required
                        />
                        <InputError
                            message={errors.start_date}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label className="mb-1.5 block">
                            End date
                            <span className="ml-0.5 text-destructive">*</span>
                        </Label>
                        <Input
                            type="date"
                            value={data.end_date ?? ''}
                            onChange={(e) =>
                                setData('end_date', e.target.value)
                            }
                            required
                        />
                        <InputError
                            message={errors.end_date}
                            className="mt-1.5"
                        />
                    </div>
                </div>

                <div>
                    <Label className="mb-1.5 block">Status</Label>
                    <Select
                        value={data.status}
                        onValueChange={(v) =>
                            setData('status', v as PeriodStatus)
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {STATUSES.map((s) => (
                                <SelectItem key={s} value={s}>
                                    {PERIOD_STATUS_LABELS[s]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        Evaluations can only be opened while a period is{' '}
                        <span className="font-medium">Open</span>.
                    </p>
                    <InputError message={errors.status} className="mt-1.5" />
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
