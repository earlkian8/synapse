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
import { payrollConfigRoutes } from '../routes';
import { DEDUCTION_KIND_LABELS } from '../types';
import type { DeductionKind, DeductionType } from '../types';

type Props = {
    type: DeductionType | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const KINDS = Object.keys(DEDUCTION_KIND_LABELS) as DeductionKind[];

export function DeductionFormSheet({ type, open, onOpenChange }: Props) {
    const isEditing = Boolean(type);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing
                            ? 'Edit deduction type'
                            : 'New deduction type'}
                    </SheetTitle>
                    <SheetDescription>
                        A deduction the payroll engine applies when building
                        payslips.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={type?.id ?? 'new'}
                        type={type}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    type,
    onDone,
}: {
    type: DeductionType | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(type);
    const isBracket = type?.computation_type === 'bracket';

    const { data, setData, post, processing, errors, transform } = useForm({
        name: type?.name ?? '',
        kind: (type?.kind ?? 'other') as DeductionKind,
        is_mandatory: type?.is_mandatory ?? false,
        rate: type?.rate != null ? String(type.rate) : '',
        cap: type?.cap != null ? String(type.cap) : '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            rate: payload.rate === '' ? null : Number(payload.rate),
            cap: payload.cap === '' ? null : Number(payload.cap),
        }));

        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && type) {
            post(payrollConfigRoutes.deduction.update(type.hashid), opts);
        } else {
            post(payrollConfigRoutes.deduction.store, opts);
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
                        placeholder="e.g. SSS Contribution"
                        required
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <div>
                    <Label className="mb-1.5 block">Kind</Label>
                    <Select
                        value={data.kind}
                        onValueChange={(v) =>
                            setData('kind', v as DeductionKind)
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {KINDS.map((kind) => (
                                <SelectItem key={kind} value={kind}>
                                    {DEDUCTION_KIND_LABELS[kind]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.kind} className="mt-1.5" />
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label className="mb-1.5 block">Rate (%)</Label>
                        <Input
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            value={data.rate}
                            onChange={(e) => setData('rate', e.target.value)}
                            placeholder="e.g. 4.5"
                            className="tabular-nums"
                        />
                        <InputError message={errors.rate} className="mt-1.5" />
                    </div>
                    <div>
                        <Label className="mb-1.5 block">Monthly cap</Label>
                        <Input
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.cap}
                            onChange={(e) => setData('cap', e.target.value)}
                            placeholder="Optional"
                            className="tabular-nums"
                        />
                        <InputError message={errors.cap} className="mt-1.5" />
                    </div>
                </div>

                <p className="text-xs text-muted-foreground">
                    Computed as a percentage of the period's gross
                    {isBracket && (
                        <>
                            {' '}
                            · this deduction currently uses a progressive
                            bracket; setting a rate replaces it
                        </>
                    )}
                    .
                </p>

                <div className="flex items-center justify-between rounded-lg border border-sidebar-border/70 px-3 py-2.5 dark:border-sidebar-border">
                    <div className="pr-3">
                        <Label className="text-sm">Mandatory</Label>
                        <p className="text-xs text-muted-foreground">
                            Applied to every payslip automatically.
                        </p>
                    </div>
                    <Switch
                        checked={data.is_mandatory}
                        onCheckedChange={(v) => setData('is_mandatory', v)}
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
