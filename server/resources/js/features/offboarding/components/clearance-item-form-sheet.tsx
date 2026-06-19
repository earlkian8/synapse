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
import { offboardingRoutes } from '../routes';
import type { ClearanceItem, DepartmentRef } from '../types';

type Props = {
    item: ClearanceItem | null;
    caseHashid: string;
    departments: DepartmentRef[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const NONE = '__none__';

export function ClearanceItemFormSheet({
    item,
    caseHashid,
    departments,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(item);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing
                            ? 'Edit clearance item'
                            : 'Add clearance item'}
                    </SheetTitle>
                    <SheetDescription>
                        {isEditing
                            ? 'Update this clearance sign-off.'
                            : 'Add a sign-off to this exit clearance.'}
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={item?.id ?? 'new'}
                        item={item}
                        caseHashid={caseHashid}
                        departments={departments}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    item,
    caseHashid,
    departments,
    onDone,
}: {
    item: ClearanceItem | null;
    caseHashid: string;
    departments: DepartmentRef[];
    onDone: () => void;
}) {
    const isEditing = Boolean(item);

    const { data, setData, post, processing, errors, transform } = useForm({
        item: item?.item ?? '',
        department_id: item?.department_id ? String(item.department_id) : NONE,
        remarks: item?.remarks ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            department_id:
                payload.department_id === NONE
                    ? null
                    : Number(payload.department_id),
            remarks: payload.remarks || null,
        }));

        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && item) {
            post(offboardingRoutes.item(item.id), opts);
        } else {
            post(offboardingRoutes.clearance(caseHashid), opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div>
                    <Label className="mb-1.5 block">
                        Item
                        <span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Input
                        value={data.item}
                        onChange={(e) => setData('item', e.target.value)}
                        placeholder="e.g. Return laptop & peripherals"
                        required
                    />
                    <InputError message={errors.item} className="mt-1.5" />
                </div>

                <div>
                    <Label className="mb-1.5 block">
                        Responsible department
                    </Label>
                    <Select
                        value={data.department_id}
                        onValueChange={(v) => setData('department_id', v)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>Unassigned</SelectItem>
                            {departments.map((d) => (
                                <SelectItem key={d.id} value={String(d.id)}>
                                    {d.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError
                        message={errors.department_id}
                        className="mt-1.5"
                    />
                </div>

                <div>
                    <Label className="mb-1.5 block">Remarks</Label>
                    <textarea
                        value={data.remarks ?? ''}
                        onChange={(e) => setData('remarks', e.target.value)}
                        rows={3}
                        placeholder="Sign-off note, or why this item is flagged…"
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                    <InputError message={errors.remarks} className="mt-1.5" />
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
                        {isEditing ? 'Save changes' : 'Add item'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}
