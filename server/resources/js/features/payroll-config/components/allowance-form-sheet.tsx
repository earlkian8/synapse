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
import { payrollConfigRoutes } from '../routes';
import type { AllowanceType } from '../types';

type Props = {
    type: AllowanceType | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function AllowanceFormSheet({ type, open, onOpenChange }: Props) {
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
                            ? 'Edit allowance type'
                            : 'New allowance type'}
                    </SheetTitle>
                    <SheetDescription>
                        A kind of additional earning a payslip can include.
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
    type: AllowanceType | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(type);
    const { data, setData, post, processing, errors } = useForm({
        name: type?.name ?? '',
        is_taxable: type?.is_taxable ?? false,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && type) {
            post(payrollConfigRoutes.allowance.update(type.hashid), opts);
        } else {
            post(payrollConfigRoutes.allowance.store, opts);
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
                        placeholder="e.g. Rice Subsidy"
                        required
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <div className="flex items-center justify-between rounded-lg border border-sidebar-border/70 px-3 py-2.5 dark:border-sidebar-border">
                    <div className="pr-3">
                        <Label className="text-sm">Taxable</Label>
                        <p className="text-xs text-muted-foreground">
                            Forms part of taxable income when on.
                        </p>
                    </div>
                    <Switch
                        checked={data.is_taxable}
                        onCheckedChange={(v) => setData('is_taxable', v)}
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
