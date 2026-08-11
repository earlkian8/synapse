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
import { departmentRoutes } from '../routes';
import type { Position } from '../types';

type Props = {
    position: Position | null;
    departmentHashid: string;
    departmentName: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function PositionFormSheet({
    position,
    departmentHashid,
    departmentName,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(position);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit position' : 'New position'}
                    </SheetTitle>
                    <SheetDescription>
                        {isEditing
                            ? 'Update this position.'
                            : `Add a position under ${departmentName}.`}
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={position?.id ?? 'new'}
                        position={position}
                        departmentHashid={departmentHashid}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    position,
    departmentHashid,
    onDone,
}: {
    position: Position | null;
    departmentHashid: string;
    onDone: () => void;
}) {
    const isEditing = Boolean(position);

    const { data, setData, post, processing, errors, transform } = useForm({
        title: position?.title ?? '',
        salary_grade_min: position?.salary_grade_min ?? '',
        salary_grade_max: position?.salary_grade_max ?? '',
        description: position?.description ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            salary_grade_min: payload.salary_grade_min || null,
            salary_grade_max: payload.salary_grade_max || null,
            description: payload.description || null,
        }));

        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && position) {
            post(departmentRoutes.position(position.id), opts);
        } else {
            post(departmentRoutes.positions(departmentHashid), opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <Field label="Title" required error={errors.title}>
                    <Input
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        placeholder="e.g. Software Engineer"
                        required
                    />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Salary (min)" error={errors.salary_grade_min}>
                        <Input
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.salary_grade_min ?? ''}
                            onChange={(e) =>
                                setData('salary_grade_min', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Salary (max)" error={errors.salary_grade_max}>
                        <Input
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.salary_grade_max ?? ''}
                            onChange={(e) =>
                                setData('salary_grade_max', e.target.value)
                            }
                        />
                    </Field>
                </div>

                <Field label="Description" error={errors.description}>
                    <textarea
                        value={data.description ?? ''}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={3}
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                </Field>
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
                        {isEditing ? 'Save changes' : 'Add position'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}

function Field({
    label,
    required = false,
    error,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <Label className="mb-1.5 block">
                {label}
                {required && <span className="ml-0.5 text-destructive">*</span>}
            </Label>
            {children}
            <InputError message={error} className="mt-1.5" />
        </div>
    );
}
