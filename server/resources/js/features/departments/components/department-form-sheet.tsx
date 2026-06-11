import { useForm } from '@inertiajs/react';
import { useMemo } from 'react';
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
import { departmentRoutes } from '../routes';
import type { Department, EmployeeOption } from '../types';

type Props = {
    department: Department | null;
    parentDefault: Department | null;
    departments: Department[];
    employees: EmployeeOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const NONE = '__none__';

/** Ids the given department may not be parented into (itself + descendants). */
function subtreeIds(department: Department | null, all: Department[]): Set<number> {
    const blocked = new Set<number>();

    if (!department) {
        return blocked;
    }

    blocked.add(department.id);
    let frontier = [department.id];

    while (frontier.length > 0) {
        const next: number[] = [];

        for (const d of all) {
            if (d.parent_id !== null && frontier.includes(d.parent_id)) {
                blocked.add(d.id);
                next.push(d.id);
            }
        }

        frontier = next;
    }

    return blocked;
}

export function DepartmentFormSheet({
    department,
    parentDefault,
    departments,
    employees,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(department);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit department' : 'New department'}
                    </SheetTitle>
                    <SheetDescription>
                        {isEditing
                            ? 'Update this department.'
                            : 'Add a department to the org structure.'}
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={department?.id ?? `new-${parentDefault?.id ?? 'root'}`}
                        department={department}
                        parentDefault={parentDefault}
                        departments={departments}
                        employees={employees}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    department,
    parentDefault,
    departments,
    employees,
    onDone,
}: {
    department: Department | null;
    parentDefault: Department | null;
    departments: Department[];
    employees: EmployeeOption[];
    onDone: () => void;
}) {
    const isEditing = Boolean(department);

    const parentOptions = useMemo(() => {
        const blocked = subtreeIds(department, departments);

        return departments.filter((d) => !blocked.has(d.id));
    }, [department, departments]);

    const { data, setData, post, processing, errors, transform } = useForm({
        name: department?.name ?? '',
        code: department?.code ?? '',
        parent_id: String(
            department?.parent_id ?? parentDefault?.id ?? '',
        ) || NONE,
        head_id: department?.head_id ? String(department.head_id) : NONE,
        description: department?.description ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            parent_id: payload.parent_id === NONE ? null : Number(payload.parent_id),
            head_id: payload.head_id === NONE ? null : Number(payload.head_id),
            description: payload.description || null,
        }));

        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && department) {
            post(departmentRoutes.department(department.hashid), opts);
        } else {
            post(departmentRoutes.store, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div className="grid gap-4 sm:grid-cols-[1fr_140px]">
                    <Field label="Name" required error={errors.name}>
                        <Input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Human Resources"
                            required
                        />
                    </Field>
                    <Field label="Code" required error={errors.code}>
                        <Input
                            value={data.code}
                            onChange={(e) =>
                                setData('code', e.target.value.toUpperCase())
                            }
                            placeholder="HR"
                            className="uppercase"
                            required
                        />
                    </Field>
                </div>

                <Field label="Parent department" error={errors.parent_id}>
                    <Select
                        value={data.parent_id}
                        onValueChange={(v) => setData('parent_id', v)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>
                                None (top level)
                            </SelectItem>
                            {parentOptions.map((d) => (
                                <SelectItem key={d.id} value={String(d.id)}>
                                    {d.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <Field label="Department head" error={errors.head_id}>
                    <Select
                        value={data.head_id}
                        onValueChange={(v) => setData('head_id', v)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Select an employee…" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>No head</SelectItem>
                            {employees.map((e) => (
                                <SelectItem key={e.id} value={String(e.id)}>
                                    {e.full_name}
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {e.employee_no}
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

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
                        {isEditing ? 'Save changes' : 'Create department'}
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
